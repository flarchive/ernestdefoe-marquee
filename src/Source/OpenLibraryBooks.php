<?php

namespace Ernestdefoe\Marquee\Source;

/**
 * Books from Open Library. No key: always available. A book is an Open
 * Library work (all its editions); the edition shown is the best match for the
 * forum's language, so a French forum gets "Le Messie de Dune" and its French
 * publisher, cover and ISBN when Open Library has that edition.
 */
class OpenLibraryBooks implements Source
{
    private const AUTHORS = 3;

    private const SEARCH_FIELDS = 'key,title,author_name,first_publish_year,cover_i,editions,editions.title,editions.language,editions.cover_i';

    private const DETAIL_FIELDS = 'key,title,subtitle,author_key,author_name,first_publish_year,cover_i,edition_count,number_of_pages_median,'
        .'ratings_average,ratings_count,want_to_read_count,subject,editions,editions.key,editions.title,editions.subtitle,editions.language,'
        .'editions.publisher,editions.publish_date,editions.number_of_pages,editions.isbn,editions.cover_i';

    /** ISO 639-1 → the MARC codes Open Library tags editions with. */
    private const MARC = [
        'ar' => 'ara', 'ca' => 'cat', 'cs' => 'cze', 'da' => 'dan', 'de' => 'ger', 'el' => 'gre', 'en' => 'eng', 'es' => 'spa',
        'fi' => 'fin', 'fr' => 'fre', 'he' => 'heb', 'hu' => 'hun', 'it' => 'ita', 'ja' => 'jpn', 'ko' => 'kor', 'nl' => 'dut',
        'no' => 'nor', 'pl' => 'pol', 'pt' => 'por', 'ro' => 'rum', 'ru' => 'rus', 'sv' => 'swe', 'tr' => 'tur', 'uk' => 'ukr',
        'vi' => 'vie', 'zh' => 'chi',
    ];

    public function __construct(private OpenLibraryClient $openLibrary, private DataLanguage $language)
    {
    }

    public function kind(): string
    {
        return 'book';
    }

    public function configured(): bool
    {
        return true;
    }

    public function search(string $query): array
    {
        $lang = $this->lang();
        $docs = $this->openLibrary->get('search.json', ['q' => $query, 'lang' => $lang, 'fields' => self::SEARCH_FIELDS, 'limit' => 8])['docs'] ?? [];

        return array_values(array_map(function (array $doc) use ($lang) {
            $edition = self::edition($doc, $lang);
            $translated = $edition && isset($edition['title']) && mb_strtolower($edition['title']) !== mb_strtolower($doc['title']);

            return [
                'id' => self::workId($doc['key'] ?? ''),
                'title' => $translated ? $edition['title'] : $doc['title'],
                'year' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
                'poster' => OpenLibraryClient::image('b', $edition['cover_i'] ?? $doc['cover_i'] ?? null),
                'subtitle' => implode(', ', array_slice($doc['author_name'] ?? [], 0, 2)),
            ];
        }, array_filter($docs, fn ($doc) => self::workId($doc['key'] ?? '') !== '' && ! empty($doc['title']))));
    }

    public function details(string $id): array
    {
        if (! preg_match('/^OL\d{1,12}W$/', $id)) {
            throw new SourceException("Open Library work ids look like OL123W, got $id");
        }

        $lang = $this->lang();
        $doc = $this->openLibrary->get('search.json', ['q' => "key:/works/$id", 'lang' => $lang, 'fields' => self::DETAIL_FIELDS, 'limit' => 1])['docs'][0] ?? null;
        if (! $doc) {
            throw new SourceException("Open Library has no work $id");
        }

        $work = $this->openLibrary->get("works/$id.json");

        $authors = [];
        foreach (array_slice($doc['author_key'] ?? [], 0, self::AUTHORS) as $key) {
            if (preg_match('/^OL\d{1,12}A$/', $key)) {
                try {
                    $authors[] = $this->openLibrary->get("authors/$key.json");
                } catch (SourceException) {
                    // The name from the search result still shows.
                }
            }
        }

        return self::normalize($id, $doc, $work, $authors, $lang);
    }

    /** Public so the shape can be checked without the network. */
    public static function normalize(string $id, array $doc, array $work, array $authors, string $lang): array
    {
        $edition = self::edition($doc, $lang);
        $workTitle = $work['title'] ?? $doc['title'];
        // Editions in the work's own language often carry sloppy casing ("Dune messiah"):
        // only a real translation replaces the work's title and subtitle. The cover comes
        // from the edition either way, since a work's own cover can be any language's.
        $translated = $edition && isset($edition['title']) && mb_strtolower($edition['title']) !== mb_strtolower($workTitle);
        $title = $translated ? $edition['title'] : $workTitle;

        $people = [];
        foreach (array_slice($doc['author_name'] ?? [], 0, self::AUTHORS) as $i => $name) {
            $author = $authors[$i] ?? [];
            $people[] = [
                'name' => $author['name'] ?? $name,
                'role' => self::lifespan($author),
                'photo' => OpenLibraryClient::image('a', $author['photos'][0] ?? null),
            ];
        }

        $isbns = $edition['isbn'] ?? [];
        usort($isbns, fn ($a, $b) => strlen($b) <=> strlen($a)); // ISBN-13 first

        return [
            'title' => $title,
            'originalTitle' => $title !== $workTitle ? $workTitle : null,
            'subtitle' => ($translated ? ($edition['subtitle'] ?? null) : null) ?? $work['subtitle'] ?? null,
            'year' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
            'authors' => array_column($people, 'name'),
            'people' => $people,
            'poster' => OpenLibraryClient::image('b', $edition['cover_i'] ?? $doc['cover_i'] ?? ($work['covers'][0] ?? null)),
            'overview' => self::description($work['description'] ?? null),
            'subjects' => self::subjects($doc['subject'] ?? []),
            'pages' => (int) ($edition['number_of_pages'] ?? $doc['number_of_pages_median'] ?? 0) ?: null,
            'publisher' => $edition['publisher'][0] ?? null,
            // Some editions carry placeholders such as "xxxx".
            'published' => preg_match('/\d{4}/', (string) ($edition['publish_date'][0] ?? '')) ? $edition['publish_date'][0] : null,
            'isbn' => $isbns[0] ?? null,
            'editions' => (int) ($doc['edition_count'] ?? 0),
            'rating' => isset($doc['ratings_average']) ? round((float) $doc['ratings_average'], 1) : null,
            'ratingCount' => (int) ($doc['ratings_count'] ?? 0),
            'readers' => (int) ($doc['want_to_read_count'] ?? 0),
            'url' => "https://openlibrary.org/works/$id",
        ];
    }

    /** The edition Open Library matched to the language, when it really is in that language. */
    private static function edition(array $doc, string $lang): ?array
    {
        $edition = $doc['editions']['docs'][0] ?? null;
        $marc = self::MARC[$lang] ?? null;

        return $edition && $marc && in_array($marc, $edition['language'] ?? [], true) ? $edition : null;
    }

    private static function workId(string $key): string
    {
        return preg_match('#^/works/(OL\d{1,12}W)$#', $key, $m) ? $m[1] : '';
    }

    private static function lifespan(array $author): ?string
    {
        $born = preg_match('/\d{4}/', (string) ($author['birth_date'] ?? ''), $b) ? $b[0] : null;
        $died = preg_match('/\d{4}/', (string) ($author['death_date'] ?? ''), $d) ? $d[0] : null;

        return $born ? $born.'–'.($died ?? '') : null;
    }

    /** Descriptions are Markdown, often with a source note and link list at the end. */
    private static function description(mixed $value): ?string
    {
        $text = is_array($value) ? ($value['value'] ?? '') : (string) $value;
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_split('/\n-{3,}/', $text)[0];
        $text = preg_replace([
            '/\(\[source\]\[\d+\]\)/i',    // ([source][1])
            '/^\s*\(source:[^)]*\)\s*$/im', // (Source: a note from the publisher)
            '/^\s*\[\d+\]:\s*\S.*$/m',     // [1]: https://…
            '/\[([^\]]+)\]\([^)]*\)/',      // [text](url)
            '/\[([^\]]+)\]\[\d+\]/',        // [text][1]
            '/(\*{1,3}|_{2})(\S(?:.*?\S)?)\1/s', // **bold**, *italic*, __bold__
        ], ['', '', '', '$1', '$1', '$2'], $text);
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));

        return $text === '' ? null : mb_substr($text, 0, 2000);
    }

    /**
     * Open Library's subjects mix genres with library bookkeeping and other
     * languages' headings further down the list; keep the first readable few.
     */
    private static function subjects(array $subjects): array
    {
        $keep = [];
        foreach ($subjects as $subject) {
            $subject = trim((string) $subject);
            $key = str_replace('-', ' ', mb_strtolower($subject));
            if ($subject === '' || mb_strlen($subject) > 32 || isset($keep[$key]) || $key === 'series'
                || preg_match('/[:(,]|\p{Mn}|translation|accessible book|daisy|in library|lending|long now|large type|reading level|new york times|juvenile/iu', $subject)) {
                continue;
            }
            $keep[$key] = mb_strtoupper(mb_substr($subject, 0, 1)).mb_substr($subject, 1);
            if (count($keep) === 4) {
                break;
            }
        }

        return array_values($keep);
    }

    private function lang(): string
    {
        return substr($this->language->get(), 0, 2);
    }
}

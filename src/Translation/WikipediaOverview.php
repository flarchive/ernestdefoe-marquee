<?php

namespace Ernestdefoe\Marquee\Translation;

use Ernestdefoe\Marquee\Source\DataLanguage;
use Ernestdefoe\Marquee\Wikimedia\Bridge;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * IGDB describes games in English only. Wikipedia often has the game in the
 * data language, and Wikidata links the two by IGDB slug (P5794), or failing
 * that by title and year, so on a non-English forum the article's lead becomes
 * the game's description, credited to Wikipedia (CC BY-SA). DeepL, when set
 * up, then translates what is left.
 *
 * Runs when a title is fetched or refreshed, daily while the article is
 * missing, and never on an English forum. Any failure leaves the data as it came.
 */
class WikipediaOverview
{
    /** Wikidata's class for video games, for finding one by title. */
    private const VIDEO_GAME = 'Q7889';

    public function __construct(private Bridge $bridge, private DataLanguage $language, private LoggerInterface $log)
    {
    }

    public function applies(string $kind): bool
    {
        $wiki = Bridge::wiki($this->language->get());

        return $kind === 'game' && $wiki !== null && $wiki !== 'en';
    }

    /**
     * Whether a game still has no description in the data language: no article
     * found, and no DeepL translation in its place.
     *
     * @param array<string, mixed> $data
     */
    public function missing(string $kind, array $data): bool
    {
        return $this->applies($kind) && ! isset($data['wikipedia']) && ! isset($data['translation']);
    }

    /**
     * @param array<string, mixed> $data fresh details from the source
     * @param array<string, mixed>|null $previous what was stored for this title before
     * @return array<string, mixed>
     */
    public function apply(string $kind, array $data, ?array $previous): array
    {
        if (! $this->applies($kind) || ! is_string($data['title'] ?? null)) {
            return $data;
        }

        $language = $this->language->get();
        $wiki = (string) Bridge::wiki($language);
        $slug = $data['slug'] ?? null;
        $before = $previous['wikipedia'] ?? null;
        $sameGame = $previous !== null && ($previous['slug'] ?? null) === $slug;

        try {
            // The game's item does not move; once found it is kept and not searched for again.
            $item = $sameGame ? ($previous['wikidata'] ?? $before['item'] ?? null) : null;
            $item ??= (is_string($slug) ? $this->bridge->item('P5794', $slug) : null)
                ?? $this->bridge->find($data['title'], is_int($data['year'] ?? null) ? $data['year'] : null, self::VIDEO_GAME);
            $summary = $item ? $this->bridge->summary($item, $wiki, $language) : null;
        } catch (RuntimeException $e) {
            $this->log->info('[marquee] Wikipedia description skipped: '.$e->getMessage());

            // Wikipedia being unreachable is no reason to drop the description it gave last week.
            if ($sameGame && ($before['lang'] ?? null) === $wiki && is_string($previous['overview'] ?? null)) {
                $data['overview'] = $previous['overview'];
                $data['wikipedia'] = $before;
            }

            return $data;
        }

        if ($item) {
            $data['wikidata'] = $item;
        }

        if ($summary === null) {
            return $data;
        }

        $data['overview'] = $summary['extract'];
        $data['wikipedia'] = ['item' => $item, 'lang' => $wiki, 'title' => $summary['title'], 'url' => $summary['url']];

        return $data;
    }
}

<?php

namespace Ernestdefoe\Marquee\Translation;

use Ernestdefoe\Marquee\Source\DataLanguage;
use Ernestdefoe\Marquee\Wikimedia\Bridge;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * IGDB describes games in English only. Wikipedia often has the game in the
 * data language, and Wikidata links the two by IGDB slug (P5794), so on a
 * non-English forum the article's lead becomes the game's description, credited
 * to Wikipedia (CC BY-SA). DeepL, when set up, then translates what is left.
 *
 * Runs only when a title is fetched or refreshed (weekly), and never on an
 * English forum. Any failure leaves the data as it came.
 */
class WikipediaOverview
{
    public function __construct(private Bridge $bridge, private DataLanguage $language, private LoggerInterface $log)
    {
    }

    /**
     * @param array<string, mixed> $data fresh details from the source
     * @param array<string, mixed>|null $previous what was stored for this title before
     * @return array<string, mixed>
     */
    public function apply(string $kind, array $data, ?array $previous): array
    {
        $language = $this->language->get();
        $wiki = Bridge::wiki($language);
        $slug = $data['slug'] ?? null;

        if ($kind !== 'game' || $wiki === null || $wiki === 'en' || ! is_string($slug)) {
            return $data;
        }

        $before = $previous['wikipedia'] ?? null;
        $sameGame = ($previous['slug'] ?? null) === $slug;

        try {
            // The slug's item does not move; only the first fetch searches for it.
            $item = $sameGame && isset($before['item']) ? $before['item'] : $this->bridge->item('P5794', $slug);
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

        if ($summary === null) {
            return $data;
        }

        $data['overview'] = $summary['extract'];
        $data['wikipedia'] = ['item' => $item, 'lang' => $wiki, 'title' => $summary['title'], 'url' => $summary['url']];

        return $data;
    }
}

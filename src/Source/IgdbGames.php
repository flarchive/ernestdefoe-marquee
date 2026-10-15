<?php

namespace Ernestdefoe\Marquee\Source;

/**
 * Games from IGDB. Image fields hold `igdb:{image_id}`; the client builds the
 * URL (images.igdb.com/igdb/image/upload/t_{size}/{image_id}.jpg).
 */
class IgdbGames implements Source
{
    /**
     * game_type ids worth starting a discussion about: main game, standalone
     * expansion, remake, remaster, expanded game, port. Leaves out DLC, bundles,
     * mods, episodes, seasons, packs, updates and forks. (IGDB kept the old
     * `category` enum values when it moved them to the game_types table.)
     */
    private const GAME_TYPES = '(0,4,8,9,10,11)';

    private const DETAIL_FIELDS = 'name,url,summary,first_release_date,aggregated_rating,aggregated_rating_count,'
        .'rating,rating_count,cover.image_id,artworks.image_id,artworks.width,artworks.height,artworks.alpha_channel,screenshots.image_id,platforms.name,'
        .'platforms.abbreviation,genres.name,game_modes.name,involved_companies.company.name,'
        .'involved_companies.developer,involved_companies.publisher,videos.video_id,videos.name,'
        .'franchises.name,collections.name';

    public function __construct(private IgdbClient $igdb)
    {
    }

    public function kind(): string
    {
        return 'game';
    }

    public function configured(): bool
    {
        return $this->igdb->configured();
    }

    public function search(string $query): array
    {
        // IGDB ranks search hits by text alone and cannot sort them, so the
        // famous game is often missing from the first few. Take 50 and rank by
        // how many ratings each has; an exact title match with ratings leads.
        $results = $this->igdb->query('games', implode(' ', [
            'search '.IgdbClient::quote($query).';',
            'fields name,first_release_date,total_rating_count,cover.image_id,platforms.abbreviation,platforms.name,genres.name;',
            'where game_type = '.self::GAME_TYPES.' & version_parent = null;',
            'limit 50;',
        ]));

        $exact = mb_strtolower(trim($query));
        usort($results, fn ($a, $b) => [
            ! (mb_strtolower($a['name']) === $exact && ! empty($a['total_rating_count'])),
            -($a['total_rating_count'] ?? 0),
        ] <=> [
            ! (mb_strtolower($b['name']) === $exact && ! empty($b['total_rating_count'])),
            -($b['total_rating_count'] ?? 0),
        ]);
        $results = array_slice($results, 0, 8);

        return array_map(fn ($g) => [
            'id' => (string) $g['id'],
            'title' => $g['name'],
            'year' => self::year($g['first_release_date'] ?? null),
            'poster' => self::image($g['cover']['image_id'] ?? null),
            'subtitle' => implode(' · ', array_filter([
                self::platformSummary(self::platforms($g['platforms'] ?? [])),
                implode(', ', array_slice(array_column($g['genres'] ?? [], 'name'), 0, 2)),
            ])),
        ], $results);
    }

    public function details(string $id): array
    {
        if (! ctype_digit($id)) {
            throw new SourceException("IGDB game ids are numeric, got $id");
        }

        $results = $this->igdb->query('multiquery', implode("\n", [
            'query games "game" { fields '.self::DETAIL_FIELDS."; where id = $id; };",
            "query game_time_to_beats \"ttb\" { fields hastily,normally,completely,count; where game_id = $id; };",
        ]));

        $named = array_column($results, 'result', 'name');
        $game = $named['game'][0] ?? null;

        if (! $game) {
            throw new SourceException("IGDB has no game $id");
        }

        return self::normalize($game, $named['ttb'][0] ?? null);
    }

    /** Public so the shape can be checked without credentials. */
    public static function normalize(array $g, ?array $ttb): array
    {
        $companies = $g['involved_companies'] ?? [];
        $role = fn (string $flag) => array_values(array_unique(array_filter(array_map(
            fn ($c) => ! empty($c[$flag]) ? ($c['company']['name'] ?? null) : null,
            $companies
        ))));

        $trailer = null;
        foreach ($g['videos'] ?? [] as $video) {
            $id = TmdbClient::youtube($video['video_id'] ?? null);
            $trailer ??= $id;
            if ($id && stripos($video['name'] ?? '', 'trailer') !== false) {
                $trailer = $id;
                break;
            }
        }

        $hours = fn ($seconds) => $seconds ? max(1, (int) round($seconds / 3600)) : null;
        // Artworks include logos and cut-outs; a backdrop needs a wide, opaque one.
        $backdrop = null;
        foreach ($g['artworks'] ?? [] as $art) {
            if (empty($art['alpha_channel']) && ($art['width'] ?? 0) >= 1280 && ($art['width'] ?? 0) >= 1.5 * ($art['height'] ?? PHP_INT_MAX)) {
                $backdrop = $art['image_id'];
                break;
            }
        }
        $backdrop ??= $g['screenshots'][0]['image_id'] ?? null;

        return [
            'title' => $g['name'],
            'year' => self::year($g['first_release_date'] ?? null),
            'date' => isset($g['first_release_date']) ? gmdate('Y-m-d', (int) $g['first_release_date']) : null,
            'genres' => array_column($g['genres'] ?? [], 'name'),
            'modes' => array_column($g['game_modes'] ?? [], 'name'),
            'platforms' => self::platforms($g['platforms'] ?? [], false),
            'critic' => isset($g['aggregated_rating']) ? (int) round($g['aggregated_rating']) : null,
            'criticCount' => $g['aggregated_rating_count'] ?? null,
            'users' => isset($g['rating']) ? (int) round($g['rating']) : null,
            'usersCount' => $g['rating_count'] ?? null,
            'overview' => $g['summary'] ?? null,
            'poster' => self::image($g['cover']['image_id'] ?? null),
            'backdrop' => self::image($backdrop),
            'screenshots' => array_map(fn ($s) => self::image($s['image_id']), array_slice($g['screenshots'] ?? [], 0, 6)),
            'crew' => [
                'developer' => $role('developer'),
                'publisher' => $role('publisher'),
                'series' => array_values(array_unique(array_merge(
                    array_column($g['franchises'] ?? [], 'name'),
                    array_column($g['collections'] ?? [], 'name')
                ))),
            ],
            'timeToBeat' => $ttb && ($ttb['count'] ?? 0) > 0 ? [
                'hastily' => $hours($ttb['hastily'] ?? null),
                'normally' => $hours($ttb['normally'] ?? null),
                'completely' => $hours($ttb['completely'] ?? null),
            ] : null,
            'trailer' => $trailer,
            'url' => str_starts_with($g['url'] ?? '', 'https://www.igdb.com/') ? $g['url'] : null,
        ];
    }

    /** "PC, PS5, Switch +4" */
    private static function platformSummary(array $names): string
    {
        $extra = count($names) - 3;

        return implode(', ', array_slice($names, 0, 3)).($extra > 0 ? " +$extra" : '');
    }

    private static function platformRank(array $platform): int
    {
        $rank = array_search($platform['id'] ?? null, self::PLATFORM_ORDER, true);

        return $rank === false ? 100 : $rank;
    }

    private static function year(?int $timestamp): ?int
    {
        return $timestamp ? (int) gmdate('Y', $timestamp) : null;
    }

    private static function image(?string $imageId): ?string
    {
        return $imageId && preg_match('/^[a-z0-9]{1,40}$/', $imageId) ? 'igdb:'.$imageId : null;
    }

    /**
     * IGDB lists platforms in id order (Linux before PC). Current major
     * platforms first: PC, PS5, PS4, Xbox Series, Xbox One, Switch 2, Switch,
     * Mac, iOS, Android; everything else after, in IGDB's order.
     */
    private const PLATFORM_ORDER = [6, 167, 48, 169, 49, 508, 130, 14, 39, 34];

    private static function platforms(array $platforms, bool $short = true): array
    {
        usort($platforms, fn ($a, $b) => self::platformRank($a) <=> self::platformRank($b));

        return array_values(array_filter(array_map(
            // Full names read better, but some are long ("PC (Microsoft Windows)").
            fn ($p) => $short || mb_strlen($p['name'] ?? '') > 18
                ? ($p['abbreviation'] ?? $p['name'] ?? null)
                : ($p['name'] ?? null),
            $platforms
        )));
    }
}

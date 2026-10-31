<?php

namespace Ernestdefoe\Marquee\Source;

/**
 * Games from IGDB. Image fields hold `igdb:{image_id}`; the client builds the
 * URL (images.igdb.com/igdb/image/upload/t_{size}/{image_id}.jpg).
 */
class IgdbGames implements Source
{
    /**
     * game_type ids worth starting a discussion about: main game, DLC,
     * expansion, bundle, standalone expansion, remake, remaster, expanded
     * game, port. Leaves out mods, episodes, seasons, packs, updates and
     * forks. Expansions and story DLC (Shadow of the Erdtree, The Forsaken
     * Hollows) are in for their own discussions (Wlork, Discuss d/40077 #77);
     * rank() keeps unrated DLC (cosmetic packs) under the games. Bundles are in
     * because IGDB files real games as bundles too (UFO 50, Collection of
     * SaGa, Mass Effect Legendary Edition); rank() keeps store bundles under
     * the games. (IGDB kept the old `category` enum values when it moved them
     * to the game_types table.)
     */
    public const GAME_TYPES = '(0,1,2,3,4,8,9,10,11)';

    /**
     * Editions of another game (version_parent set) are searched too: IGDB
     * files some real releases as an edition, such as "Shovel Knight: Treasure
     * Trove", "Scott Pilgrim vs. the World: The Game - Complete Edition" and
     * "Romancing SaGa: Minstrel Song Remastered International Special Edition"
     * (Wlork, Discuss d/40107 #73, #83). rank() keeps them under their game, so
     * a search isn't led by Deluxe and GOTY editions.
     */
    public const WHERE = 'where game_type = '.self::GAME_TYPES.';';

    private const DLC = 1;

    /**
     * What a result is when it isn't a main game, shown as a label in the
     * picker so versions can be told apart (Wlork, Discuss d/40077 #80).
     */
    private const TYPE_LABELS = [1 => 'dlc', 2 => 'expansion', 3 => 'bundle', 4 => 'standalone', 8 => 'remake', 9 => 'remaster', 10 => 'expanded', 11 => 'port'];

    private const BUNDLE = 3;

    private const DETAIL_FIELDS = 'name,slug,url,summary,first_release_date,aggregated_rating,aggregated_rating_count,'
        .'rating,rating_count,cover.image_id,artworks.image_id,artworks.width,artworks.height,artworks.alpha_channel,screenshots.image_id,platforms.name,'
        .'platforms.abbreviation,genres.name,genres.slug,game_modes.name,game_modes.slug,involved_companies.company.name,'
        .'involved_companies.developer,involved_companies.publisher,videos.video_id,videos.name,'
        .'franchises.name,collections.name,game_localizations.name,game_localizations.region.identifier,game_type,version_parent';

    public function __construct(private IgdbClient $igdb, private DataLanguage $language)
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
            'fields name,game_type,version_parent,first_release_date,total_rating_count,cover.image_id,platforms.abbreviation,platforms.name,genres.name,'
                .'involved_companies.company.name,involved_companies.developer;',
            self::WHERE,
            'limit 50;',
        ]));

        $results = self::rank($results, $query);
        $results = array_slice($results, 0, 8);

        return array_map(fn ($g) => [
            'id' => (string) $g['id'],
            // The picker's second line and chips. `subtitle` keeps its shape: Curio reads it.
            'developer' => implode(', ', array_slice(self::developers($g['involved_companies'] ?? []), 0, 2)) ?: null,
            'platforms' => self::platforms($g['platforms'] ?? []),
            'type' => self::typeLabel($g),
            'title' => $g['name'],
            'year' => self::year($g['first_release_date'] ?? null),
            'poster' => self::image($g['cover']['image_id'] ?? null),
            'subtitle' => implode(' · ', array_filter([
                self::platformSummary(self::platforms($g['platforms'] ?? [])),
                implode(', ', array_slice(array_column($g['genres'] ?? [], 'name'), 0, 2)),
            ])),
        ], $results);
    }

    /**
     * An exact title (punctuation aside) that people have rated, or that is an
     * edition, first; then the most rated. A bundle or DLC nobody has rated
     * ("Halo 3 + Halo Wars", "Elden Ring: Tarnished Pack") is a shop listing,
     * not a game people know, so it comes after every game; a rated one (UFO 50, Mass
     * Effect Trilogy) ranks like any game. An edition (Deluxe, Collector's,
     * GOTY) never comes before the game it is an edition of. Public so it is
     * tested without credentials.
     *
     * @param list<mixed> $games
     * @return list<array<string, mixed>>
     */
    public static function rank(array $games, string $query): array
    {
        $games = array_values(array_filter($games, fn ($g) => is_array($g) && isset($g['id'], $g['name'])));
        $exact = self::plain($query);
        $ratings = array_combine(array_column($games, 'id'), array_map(fn ($g) => (int) ($g['total_rating_count'] ?? 0), $games));
        $key = function (array $g) use ($exact, $ratings) {
            $count = (int) ($g['total_rating_count'] ?? 0);
            $parent = $g['version_parent'] ?? null;
            $named = self::plain((string) $g['name']) === $exact;
            $edition = $parent !== null && ! $named;

            return [
                ! ($named && ($count > 0 || $parent !== null)),
                in_array($g['game_type'] ?? null, [self::DLC, self::BUNDLE], true) && $count === 0,
                // An edition ranks by its own ratings, but below its game.
                -($edition && array_key_exists($parent, $ratings) ? min($count, $ratings[$parent]) : $count),
                $edition,
            ];
        };
        usort($games, fn ($a, $b) => $key($a) <=> $key($b));

        return $games;
    }

    /**
     * dlc, expansion, bundle…; edition for a main game filed under another; null for a game. Public for tests.
     *
     * @param array<string, mixed> $game
     */
    public static function typeLabel(array $game): ?string
    {
        return self::TYPE_LABELS[$game['game_type'] ?? 0] ?? (isset($game['version_parent']) ? 'edition' : null);
    }

    /** "Romancing SaGa: Minstrel Song" and "romancing saga minstrel song" are the same title. */
    private static function plain(string $title): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($title));
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

        return self::normalize($game, $named['ttb'][0] ?? null, $this->language->get());
    }

    /** Public so the shape can be checked without credentials. */
    public static function normalize(array $g, ?array $ttb, string $language = 'en'): array
    {
        // IGDB keeps regional names (ja-JP, ko-KR); use one if it matches.
        foreach ($g['game_localizations'] ?? [] as $local) {
            $region = strtolower($local['region']['identifier'] ?? '');
            if (! empty($local['name']) && $region !== '' && str_starts_with($region, strtolower(substr($language, 0, 2)))) {
                $g['name'] = $local['name'];
                break;
            }
        }

        $companies = $g['involved_companies'] ?? [];
        $slugs = fn (array $list) => array_map(fn ($x) => is_string($x['slug'] ?? null) && preg_match('/^[a-z0-9-]{1,60}$/', $x['slug']) ? $x['slug'] : null, $list);
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
            // IGDB's fixed genre and mode lists, translated by key on the forum (forum.genre.*, forum.mode.*).
            'genreSlugs' => $slugs(array_values(array_filter($g['genres'] ?? [], fn ($x) => isset($x['name'])))),
            'modeSlugs' => $slugs(array_values(array_filter($g['game_modes'] ?? [], fn ($x) => isset($x['name'])))),
            'platforms' => self::platforms($g['platforms'] ?? [], false),
            // Expansion, remake…: the card's first chip (null for a game).
            'type' => self::typeLabel($g),
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
            // Wikidata knows games by this (P5794); it leads to Wikipedia's description in other languages.
            'slug' => preg_match('/^[a-z0-9-]{1,100}$/', $g['slug'] ?? '') ? $g['slug'] : null,
        ];
    }

    /** @return list<string> */
    private static function developers(array $companies): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($c) => ! empty($c['developer']) && is_string($c['company']['name'] ?? null) ? $c['company']['name'] : null,
            $companies
        ))));
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

<?php

namespace Ernestdefoe\Marquee\Source;

class TmdbShows implements SeasonSource
{
    public function __construct(private TmdbClient $tmdb)
    {
    }

    public function kind(): string
    {
        return 'tv';
    }

    public function configured(): bool
    {
        return $this->tmdb->configured();
    }

    public function search(string $query): array
    {
        $genres = $this->tmdb->genres('tv');
        $results = $this->tmdb->get('search/tv', ['query' => $query, 'include_adult' => 'false'])['results'] ?? [];

        return array_map(fn ($r) => [
            'id' => (string) $r['id'],
            'title' => $r['name'],
            'year' => TmdbClient::year($r['first_air_date'] ?? null),
            'poster' => TmdbClient::path($r['poster_path'] ?? null),
            'subtitle' => implode(', ', array_slice(array_filter(array_map(fn ($g) => $genres[$g] ?? null, $r['genre_ids'] ?? [])), 0, 2)),
        ], array_slice($results, 0, 8));
    }

    public function details(string $id): array
    {
        $s = $this->tmdb->get("tv/$id", ['append_to_response' => 'aggregate_credits,content_ratings,videos']);

        if (empty($s['id'])) {
            throw new SourceException("TMDB has no show $id");
        }

        $certification = null;
        foreach ($s['content_ratings']['results'] ?? [] as $r) {
            if ($r['iso_3166_1'] === 'US') {
                $certification = $r['rating'] ?: null;
            }
        }

        $seasons = array_values(array_map(fn ($season) => [
            'number' => (int) $season['season_number'],
            'name' => $season['name'],
            'year' => TmdbClient::year($season['air_date'] ?? null),
            'episodes' => (int) $season['episode_count'],
            'poster' => TmdbClient::path($season['poster_path'] ?? null),
            'score' => TmdbClient::score($season['vote_average'] ?? null),
        ], array_filter($s['seasons'] ?? [], fn ($season) => $season['season_number'] > 0 && $season['episode_count'] > 0)));

        return [
            'title' => $s['name'],
            'year' => TmdbClient::year($s['first_air_date'] ?? null),
            'endYear' => in_array($s['status'] ?? '', ['Ended', 'Canceled'], true) ? TmdbClient::year($s['last_air_date'] ?? null) : null,
            'status' => $s['status'] ?? null,
            'certification' => $certification,
            'networks' => array_column($s['networks'] ?? [], 'name'),
            'genres' => array_column($s['genres'] ?? [], 'name'),
            'score' => TmdbClient::score($s['vote_average'] ?? null),
            'tagline' => $s['tagline'] ?: null,
            'overview' => $s['overview'] ?: null,
            'poster' => TmdbClient::path($s['poster_path'] ?? null),
            'backdrop' => TmdbClient::path($s['backdrop_path'] ?? null),
            'crew' => ['creator' => array_column($s['created_by'] ?? [], 'name')],
            'cast' => TmdbClient::people($s['aggregate_credits']['cast'] ?? [], 8, fn ($p) => $p['roles'][0]['character'] ?? ''),
            'trailer' => TmdbClient::trailer($s['videos'] ?? []),
            'seasons' => $seasons,
            'url' => "https://www.themoviedb.org/tv/$id",
        ];
    }

    public function season(string $id, int $number): array
    {
        $s = $this->tmdb->get("tv/$id/season/$number");

        if (empty($s['episodes'])) {
            throw new SourceException("TMDB has no season $number of show $id");
        }

        return [
            'number' => $number,
            'name' => $s['name'] ?? null,
            'overview' => $s['overview'] ?: null,
            'year' => TmdbClient::year($s['air_date'] ?? null),
            'poster' => TmdbClient::path($s['poster_path'] ?? null),
            'score' => TmdbClient::score($s['vote_average'] ?? null),
            'episodes' => array_map(fn ($e) => [
                'n' => (int) $e['episode_number'],
                'title' => $e['name'],
                'date' => $e['air_date'] ?: null,
                'runtime' => $e['runtime'] ?: null,
                'score' => TmdbClient::score($e['vote_average'] ?? null),
                'still' => TmdbClient::path($e['still_path'] ?? null),
                'overview' => $e['overview'] ?: null,
                'directors' => TmdbClient::crew($e['crew'] ?? [], ['Director']),
                'writers' => TmdbClient::crew($e['crew'] ?? [], ['Writer', 'Teleplay', 'Story']),
                'guests' => TmdbClient::people($e['guest_stars'] ?? [], 8, fn ($p) => $p['character'] ?? ''),
            ], $s['episodes']),
            'url' => "https://www.themoviedb.org/tv/$id/season/$number",
        ];
    }
}

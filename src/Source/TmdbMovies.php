<?php

namespace Ernestdefoe\Marquee\Source;

class TmdbMovies implements Source
{
    public function __construct(private TmdbClient $tmdb)
    {
    }

    public function kind(): string
    {
        return 'movie';
    }

    public function configured(): bool
    {
        return $this->tmdb->configured();
    }

    public function search(string $query): array
    {
        $genres = $this->tmdb->genres('movie');
        $results = $this->tmdb->get('search/movie', ['query' => $query, 'include_adult' => 'false'])['results'] ?? [];

        return array_map(fn ($r) => [
            'id' => (string) $r['id'],
            'title' => $r['title'],
            'year' => TmdbClient::year($r['release_date'] ?? null),
            'poster' => TmdbClient::path($r['poster_path'] ?? null),
            'subtitle' => implode(', ', array_slice(array_filter(array_map(fn ($g) => $genres[$g] ?? null, $r['genre_ids'] ?? [])), 0, 2)),
        ], array_slice($results, 0, 8));
    }

    public function details(string $id): array
    {
        $m = $this->tmdb->get("movie/$id", ['append_to_response' => 'credits,release_dates,videos']);

        if (empty($m['id'])) {
            throw new SourceException("TMDB has no movie $id");
        }

        $certification = null;
        foreach ($m['release_dates']['results'] ?? [] as $country) {
            if ($country['iso_3166_1'] === 'US') {
                foreach ($country['release_dates'] as $r) {
                    $certification = $certification ?: ($r['certification'] ?: null);
                }
            }
        }

        $crew = $m['credits']['crew'] ?? [];

        return [
            'title' => $m['title'],
            'year' => TmdbClient::year($m['release_date'] ?? null),
            'date' => $m['release_date'] ?: null,
            'runtime' => $m['runtime'] ?: null,
            'certification' => $certification,
            'genres' => array_column($m['genres'] ?? [], 'name'),
            'score' => TmdbClient::score($m['vote_average'] ?? null),
            'tagline' => $m['tagline'] ?: null,
            'overview' => $m['overview'] ?: null,
            'poster' => TmdbClient::path($m['poster_path'] ?? null),
            'backdrop' => TmdbClient::path($m['backdrop_path'] ?? null),
            'crew' => [
                'director' => TmdbClient::crew($crew, ['Director']),
                'writer' => TmdbClient::crew($crew, ['Screenplay', 'Writer']),
                'basedOn' => TmdbClient::crew($crew, ['Novel', 'Book', 'Comic Book', 'Characters']),
            ],
            'cast' => TmdbClient::people($m['credits']['cast'] ?? [], 8, fn ($p) => $p['character'] ?? ''),
            'trailer' => TmdbClient::trailer($m['videos'] ?? []),
            'url' => "https://www.themoviedb.org/movie/$id",
        ];
    }
}

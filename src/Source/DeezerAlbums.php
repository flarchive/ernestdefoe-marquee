<?php

namespace Ernestdefoe\Marquee\Source;

/** Albums from Deezer. No key: always available. */
class DeezerAlbums implements Source
{
    public function __construct(private DeezerClient $deezer)
    {
    }

    public function kind(): string
    {
        return 'music';
    }

    public function configured(): bool
    {
        return true;
    }

    public function search(string $query): array
    {
        $results = $this->deezer->get('search/album', ['q' => $query, 'limit' => 10])['data'] ?? [];

        return array_values(array_map(fn ($a) => [
            'id' => (string) $a['id'],
            'title' => $a['title'],
            'year' => null, // search results carry no date; the lookup fills it in
            'poster' => DeezerClient::image('cover', $a['md5_image'] ?? null),
            'artist' => $a['artist']['name'] ?? null,
            // Worded on the forum ("1 track", "11 tracks"), so it is translatable.
            'subtitle' => $a['artist']['name'] ?? '',
            'tracks' => isset($a['nb_tracks']) ? (int) $a['nb_tracks'] : null,
        ], array_slice($results, 0, 8)));
    }

    public function details(string $id): array
    {
        if (! ctype_digit($id)) {
            throw new SourceException("Deezer album ids are numeric, got $id");
        }

        return self::normalize($this->deezer->get("album/$id"));
    }

    /** Public so the shape can be checked without the network. */
    public static function normalize(array $a): array
    {
        if (empty($a['id'])) {
            throw new SourceException('Deezer returned no album');
        }

        $artist = $a['artist']['name'] ?? null;
        $tracks = [];
        foreach ($a['tracks']['data'] ?? [] as $i => $t) {
            $by = $t['artist']['name'] ?? $artist;
            $tracks[] = [
                'n' => $i + 1,
                'title' => $t['title'],
                'duration' => (int) ($t['duration'] ?? 0),
                'explicit' => (bool) ($t['explicit_lyrics'] ?? false),
                // Only when it is not the album's own artist (features, compilations).
                'artist' => $by !== $artist ? $by : null,
            ];
        }

        return [
            'title' => $a['title'],
            'year' => ! empty($a['release_date']) ? (int) substr($a['release_date'], 0, 4) : null,
            'date' => $a['release_date'] ?? null,
            'artist' => $artist,
            'label' => $a['label'] ?? null,
            'genres' => array_column($a['genres']['data'] ?? [], 'name'),
            'recordType' => in_array($a['record_type'] ?? '', ['album', 'single', 'ep', 'compile'], true) ? $a['record_type'] : 'album',
            'trackCount' => (int) ($a['nb_tracks'] ?? count($tracks)),
            'duration' => (int) ($a['duration'] ?? 0),
            'fans' => (int) ($a['fans'] ?? 0),
            'explicit' => (bool) ($a['explicit_lyrics'] ?? false),
            'poster' => DeezerClient::image('cover', $a['md5_image'] ?? ($a['cover_xl'] ?? null)),
            'backdrop' => DeezerClient::image('artist', $a['artist']['picture_xl'] ?? null),
            'tracks' => $tracks,
            'url' => str_starts_with($a['link'] ?? '', 'https://www.deezer.com/') ? $a['link'] : 'https://www.deezer.com/album/'.(int) $a['id'],
        ];
    }
}

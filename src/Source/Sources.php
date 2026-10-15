<?php

namespace Ernestdefoe\Marquee\Source;

/** Every source, by kind. */
class Sources
{
    /** @var array<string, Source> */
    private array $sources;

    public function __construct(TmdbMovies $movies, TmdbShows $shows, IgdbGames $games, DeezerAlbums $music)
    {
        foreach ([$movies, $shows, $games, $music] as $source) {
            $this->sources[$source->kind()] = $source;
        }
    }

    public function get(string $kind): ?Source
    {
        $source = $this->sources[$kind] ?? null;

        return $source && $source->configured() ? $source : null;
    }

    /** Kinds that are ready to use. */
    public function kinds(): array
    {
        return array_keys(array_filter($this->sources, fn (Source $s) => $s->configured()));
    }
}

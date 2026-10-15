<?php

namespace Ernestdefoe\Marquee\Source;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * When a source fails, remember it for a minute so the forum stops asking:
 * while TMDB or IGDB is down, searches, lookups and season loads answer at once
 * instead of each waiting on a timeout and adding to the pile.
 */
class Outage
{
    private const SECONDS = 60;

    public function __construct(private Cache $cache)
    {
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     * @throws SourceException
     */
    public function guard(string $kind, callable $call): mixed
    {
        if ($this->cache->has($this->key($kind))) {
            throw new SourceException("$kind source marked down");
        }

        try {
            return $call();
        } catch (SourceException $e) {
            // A title the source does not know is not an outage.
            if ($e->getPrevious() !== null) {
                $this->cache->put($this->key($kind), true, self::SECONDS);
            }
            throw $e;
        }
    }

    private function key(string $kind): string
    {
        return 'marquee.down.'.$kind;
    }
}

<?php

namespace Ernestdefoe\Marquee\Api;

use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;

/**
 * Each search or lookup can cost a call to the source. Typing is debounced on
 * the client; this caps what one member can make the forum send regardless.
 */
trait Throttle
{
    private function throttled(Cache $cache, User $actor, int $perMinute = 40): ?JsonResponse
    {
        $key = 'marquee.throttle.'.$actor->id.'.'.intdiv(time(), 60);
        $cache->add($key, 0, 120);

        if ($cache->increment($key) > $perMinute) {
            return new JsonResponse(['errors' => [['status' => '429', 'code' => 'too_many_requests']]], 429);
        }

        return null;
    }
}

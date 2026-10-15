<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\MediaRepository;
use Ernestdefoe\Marquee\Source\SourceException;
use Ernestdefoe\Marquee\Source\Sources;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The picked title, stored and summarised for the composer (its seasons, for a
 * show): POST /marquee/media {kind, id}. POST because it stores the title: a GET
 * could be triggered from another site by an <img>, POST needs Flarum's CSRF token.
 */
class LookupController implements RequestHandlerInterface
{
    use Throttle;

    public function __construct(private Sources $sources, private MediaRepository $media, private Cache $cache)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('startDiscussion');

        $body = (array) $request->getParsedBody();
        $kind = (string) Arr::get($body, 'kind');
        $id = (string) Arr::get($body, 'id');

        if (! $this->sources->get($kind) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return new JsonResponse(['errors' => [['status' => '422', 'code' => 'validation_error']]], 422);
        }

        if ($limited = $this->throttled($this->cache, $actor)) {
            return $limited;
        }

        try {
            $media = $this->media->resolve($kind, $id);
        } catch (SourceException) {
            return new JsonResponse(['errors' => [['status' => '502', 'code' => 'source_unavailable']]], 502);
        }

        return new JsonResponse(['data' => [
            'id' => $media->id,
            'kind' => $media->kind,
            'externalId' => $media->external_id,
            'title' => $media->title,
            'year' => $media->year,
            'poster' => $media->poster,
            'artist' => $media->data['artist'] ?? null,
            'seasons' => $media->data['seasons'] ?? null,
        ]]);
    }
}

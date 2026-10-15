<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\MediaRepository;
use Ernestdefoe\Marquee\Source\SourceException;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One season of a show the forum already knows, with every episode:
 * GET /marquee/media/{id}/seasons/{number}. Only stored shows and their real
 * season numbers are fetched, so this cannot be used to query the source freely.
 */
class SeasonController implements RequestHandlerInterface
{
    public function __construct(private MediaRepository $media)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('viewForum');

        $media = Media::query()->find((int) Arr::get($request->getAttribute('routeParameters'), 'id'));
        $number = (int) Arr::get($request->getAttribute('routeParameters'), 'number');

        if (! $media || ! in_array($number, $media->seasonNumbers(), true)) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404);
        }

        try {
            $season = $this->media->season($media, $number);
        } catch (SourceException) {
            return new JsonResponse(['errors' => [['status' => '502', 'code' => 'source_unavailable']]], 502);
        }

        return new JsonResponse(['data' => $season->data], 200, ['Cache-Control' => 'private, max-age=3600']);
    }
}

<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\Backdrops;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A title's own background on a discussion's card: POST
 * /marquee/discussions/{id}/backdrop with the image as `backdrop` and the
 * title's id as `media` (the linked title when left out). Allowed to whoever
 * may change the discussion's linked title. Replaces (and deletes) the one it had.
 */
class BackdropController implements RequestHandlerInterface
{
    use Throttle;

    public function __construct(
        private Backdrops $backdrops,
        private BackdropValidator $validator,
        private TranslatorInterface $translator,
        private Cache $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $discussion = Discussion::whereVisibleTo($actor)->findOrFail((int) Arr::get($request->getAttribute('routeParameters'), 'id'));

        $ids = DiscussionFields::titleIds($discussion);
        if (! $ids || ! DiscussionFields::canLink($actor, $discussion)) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403);
        }

        if ($limited = $this->throttled($this->cache, $actor, 10)) {
            return $limited;
        }

        $file = Arr::get($request->getUploadedFiles(), 'backdrop');

        if (! $file instanceof UploadedFileInterface) {
            throw new ValidationException(['backdrop' => $this->translator->trans('validation.required', ['attribute' => 'backdrop'])]);
        }

        $media = (int) (Arr::get((array) $request->getParsedBody(), 'media') ?: $ids[0]);
        if (! in_array($media, $ids, true)) {
            throw new ValidationException(['media' => $this->translator->trans('validation.in', ['attribute' => 'media'])]);
        }

        $this->validator->assertImageValid('backdrop', $file);

        $map = (array) ($discussion->marquee_backdrops ?? []);
        $before = $map[$media] ?? null;
        $map[$media] = $this->backdrops->store($discussion->id, (string) $file->getStream()->getMetadata('uri'));
        $discussion->marquee_backdrops = $map;
        $discussion->save();
        $this->backdrops->delete($before);

        return new JsonResponse(['data' => ['backdrops' => $this->backdrops->values($map)]]);
    }
}

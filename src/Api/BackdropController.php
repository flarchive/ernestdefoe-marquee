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
 * A discussion's own card background: POST /marquee/discussions/{id}/backdrop
 * with the image as `backdrop`. Allowed to whoever may change the discussion's
 * linked title. Replaces (and deletes) the one it had.
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

        if (! $discussion->marquee_media_id || ! DiscussionFields::canLink($actor, $discussion)) {
            return new JsonResponse(['errors' => [['status' => '403', 'code' => 'permission_denied']]], 403);
        }

        if ($limited = $this->throttled($this->cache, $actor, 10)) {
            return $limited;
        }

        $file = Arr::get($request->getUploadedFiles(), 'backdrop');

        if (! $file instanceof UploadedFileInterface) {
            throw new ValidationException(['backdrop' => $this->translator->trans('validation.required', ['attribute' => 'backdrop'])]);
        }

        $this->validator->assertImageValid('backdrop', $file);

        $before = $discussion->marquee_backdrop;
        $discussion->marquee_backdrop = $this->backdrops->store($discussion->id, (string) $file->getStream()->getMetadata('uri'));
        $discussion->save();
        $this->backdrops->delete($before);

        return new JsonResponse(['data' => ['backdrop' => $this->backdrops->value($discussion->marquee_backdrop)]]);
    }
}

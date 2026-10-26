<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\Source\DataLanguage;
use Ernestdefoe\Marquee\Source\Outage;
use Ernestdefoe\Marquee\Source\SourceException;
use Ernestdefoe\Marquee\Source\Sources;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Title search for the composer: GET /marquee/search?kind=movie&q=dune */
class SearchController implements RequestHandlerInterface
{
    use Throttle;

    public function __construct(private Sources $sources, private Cache $cache, private Outage $outage, private DataLanguage $language)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('startDiscussion');

        $params = $request->getQueryParams();
        $source = $this->sources->get((string) Arr::get($params, 'kind'));
        $query = trim(mb_substr((string) Arr::get($params, 'q'), 0, 100));

        if (! $source || mb_strlen($query) < 2) {
            return new JsonResponse(['data' => []]);
        }

        if ($limited = $this->throttled($this->cache, $actor)) {
            return $limited;
        }

        try {
            $results = $this->cache->remember(
                // The language is part of the key: switching it must not serve old results.
                'marquee.search.3.'.$source->kind().'.'.$this->language->get().'.'.md5(mb_strtolower($query)),
                86400,
                fn () => $this->outage->guard($source->kind(), fn () => $source->search($query))
            );
        } catch (SourceException) {
            return new JsonResponse(['errors' => [['status' => '502', 'code' => 'source_unavailable']]], 502);
        }

        return new JsonResponse(['data' => $results]);
    }
}

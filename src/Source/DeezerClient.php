<?php

namespace Ernestdefoe\Marquee\Source;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Deezer's public API (api.deezer.com): album search and details need no key.
 * Deezer answers errors with HTTP 200 and an `error` object, so those are read
 * here. Quota and busy errors count as an outage; "no data" does not.
 */
class DeezerClient
{
    private const BASE = 'https://api.deezer.com/';

    /** Deezer error codes that mean "back off", not "no such album". */
    private const OUTAGE_CODES = [4, 700];

    private ?Client $http = null;

    /** @throws SourceException */
    public function get(string $path, array $query = []): array
    {
        $this->http ??= new Client(['base_uri' => self::BASE, 'timeout' => 6, 'connect_timeout' => 3]);

        try {
            $data = json_decode((string) $this->http->get($path, ['query' => $query])->getBody(), true) ?: [];
        } catch (GuzzleException $e) {
            throw new SourceException('Deezer request failed: '.$path, 0, $e);
        }

        if (isset($data['error'])) {
            $code = (int) ($data['error']['code'] ?? 0);
            $message = 'Deezer error '.$code.' on '.$path;
            throw in_array($code, self::OUTAGE_CODES, true)
                ? new SourceException($message, 0, new RuntimeException($message))
                : new SourceException($message);
        }

        return $data;
    }

    /** A Deezer image as `deezer:{type}:{md5}`, from an md5 or an image URL. */
    public static function image(string $type, ?string $md5OrUrl): ?string
    {
        if ($md5OrUrl && preg_match('#(?:^|/images/'.$type.'/)([0-9a-f]{32})(?:/|$)#', $md5OrUrl, $m)) {
            return 'deezer:'.$type.':'.$m[1];
        }

        return null;
    }
}

<?php

namespace Ernestdefoe\Marquee\Source;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * Open Library's public API (openlibrary.org): search, works and authors need
 * no key. It asks callers to name themselves in the User-Agent, which also
 * earns a higher rate limit. Connection errors, 5xx and 429 count as an
 * outage; a 404 is just a book it does not know.
 */
class OpenLibraryClient
{
    private const BASE = 'https://openlibrary.org/';

    private const AGENT = 'Marquee for Flarum (+https://github.com/ernestdefoe/marquee)';

    private ?Client $http = null;

    /** @throws SourceException */
    public function get(string $path, array $query = []): array
    {
        $this->http ??= new Client(['base_uri' => self::BASE, 'timeout' => 8, 'connect_timeout' => 3, 'headers' => ['User-Agent' => self::AGENT]]);

        try {
            return json_decode((string) $this->http->get($path, ['query' => $query])->getBody(), true) ?: [];
        } catch (GuzzleException $e) {
            $status = $e instanceof RequestException ? $e->getResponse()?->getStatusCode() : null;
            $outage = $e instanceof ConnectException || $status === null || $status === 429 || $status >= 500;
            $message = 'Open Library request failed: '.$path;

            throw $outage ? new SourceException($message, 0, new RuntimeException($message, 0, $e)) : new SourceException($message);
        }
    }

    /** A cover (`ol:b:{id}`) or author photo (`ol:a:{id}`) from Open Library's numeric image id. */
    public static function image(string $type, mixed $id): ?string
    {
        return is_int($id) && $id > 0 ? "ol:$type:$id" : null;
    }
}

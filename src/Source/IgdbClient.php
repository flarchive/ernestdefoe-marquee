<?php

namespace Ernestdefoe\Marquee\Source;

use Ernestdefoe\Marquee\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * IGDB (api-docs.igdb.com): Twitch app credentials buy a bearer token, then
 * every query is a POST of an Apicalypse body to https://api.igdb.com/v4/{endpoint}.
 *
 * Tokens last about 60 days and only 25 may be active per app, so one is kept
 * in the cache and reused until shortly before it expires. IGDB allows 4
 * requests a second; calls are paced to stay under that.
 */
class IgdbClient
{
    private const TOKEN_URL = 'https://id.twitch.tv/oauth2/token';
    private const BASE = 'https://api.igdb.com/v4/';
    private const TOKEN_KEY = 'marquee.igdb.token';
    private const PER_SECOND = 4;

    private ?Client $http = null;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Cache $cache
    ) {
    }

    public function configured(): bool
    {
        return $this->settings->get(Settings::IGDB_CLIENT_ID) && $this->settings->get(Settings::IGDB_CLIENT_SECRET);
    }

    /**
     * Run an Apicalypse query against one endpoint (or `multiquery`).
     *
     * @throws SourceException
     */
    public function query(string $endpoint, string $body): array
    {
        try {
            return $this->send($endpoint, $body);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();

            // A revoked or expired token: fetch a fresh one and try once more.
            if ($status === 401) {
                $this->cache->forget(self::TOKEN_KEY);
            } elseif ($status === 429) {
                usleep(400000);
            } else {
                // A 4xx is about this query, not an outage (see Outage).
                throw new SourceException("IGDB rejected the $endpoint query ($status)");
            }
        } catch (GuzzleException $e) {
            throw new SourceException("IGDB request failed: $endpoint", 0, $e);
        }

        try {
            return $this->send($endpoint, $body);
        } catch (GuzzleException $e) {
            throw new SourceException("IGDB request failed: $endpoint", 0, $e);
        }
    }

    /** @throws GuzzleException|SourceException */
    private function send(string $endpoint, string $body): array
    {
        $this->pace();

        $response = $this->http()->post(self::BASE.$endpoint, [
            'headers' => [
                'Client-ID' => $this->settings->get(Settings::IGDB_CLIENT_ID),
                'Authorization' => 'Bearer '.$this->token(),
                'Accept' => 'application/json',
            ],
            'body' => $body,
        ]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /** @throws SourceException */
    private function token(): string
    {
        $token = $this->cache->get(self::TOKEN_KEY);
        if ($token) {
            return $token;
        }

        try {
            // In the body, not the URL: a failed request's message quotes the URL, and the secret must never reach a log.
            $response = $this->http()->post(self::TOKEN_URL, ['form_params' => [
                'client_id' => $this->settings->get(Settings::IGDB_CLIENT_ID),
                'client_secret' => $this->settings->get(Settings::IGDB_CLIENT_SECRET),
                'grant_type' => 'client_credentials',
            ]]);
        } catch (GuzzleException $e) {
            throw new SourceException('Twitch did not issue an IGDB token', 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true) ?: [];
        if (empty($data['access_token'])) {
            throw new SourceException('Twitch did not issue an IGDB token');
        }

        // Renew a day early (or at half-life for a short-lived token).
        $ttl = (int) ($data['expires_in'] ?? 3600);
        $this->cache->put(self::TOKEN_KEY, $data['access_token'], max(60, $ttl > 172800 ? $ttl - 86400 : intdiv($ttl, 2)));

        return $data['access_token'];
    }

    /** Hold back when this forum has already made 4 calls this second. */
    private function pace(): void
    {
        $key = 'marquee.igdb.rate.'.time();
        $this->cache->add($key, 0, 5);

        if ($this->cache->increment($key) > self::PER_SECOND) {
            usleep((int) ((1 - fmod(microtime(true), 1)) * 1000000) + 20000);
        }
    }

    private function http(): Client
    {
        return $this->http ??= new Client(['timeout' => 6, 'connect_timeout' => 3]);
    }

    /** An Apicalypse string literal. */
    public static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['', '\\"'], $value).'"';
    }
}

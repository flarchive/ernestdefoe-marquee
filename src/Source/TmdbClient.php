<?php

namespace Ernestdefoe\Marquee\Source;

use Ernestdefoe\Marquee\Settings;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as Cache;

class TmdbClient
{
    private const BASE = 'https://api.themoviedb.org/3/';

    private ?Client $http = null;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Cache $cache,
        private DataLanguage $language
    ) {
    }

    public function configured(): bool
    {
        return (bool) $this->settings->get(Settings::TMDB_TOKEN);
    }

    public function language(): string
    {
        return $this->language->get();
    }

    /** @throws SourceException */
    public function get(string $path, array $query = []): array
    {
        $this->http ??= new Client(['base_uri' => self::BASE, 'timeout' => 6, 'connect_timeout' => 3]);

        try {
            $response = $this->http->get($path, [
                'headers' => ['Authorization' => 'Bearer '.$this->settings->get(Settings::TMDB_TOKEN), 'Accept' => 'application/json'],
                'query' => $query + ['language' => $this->language()],
            ]);
        } catch (ClientException $e) {
            // 4xx (an id TMDB does not know, a bad request) is about this call,
            // not an outage: no previous exception, so Outage leaves it alone.
            if ($e->getResponse()->getStatusCode() !== 429) {
                throw new SourceException('TMDB refused: '.$path);
            }
            throw new SourceException('TMDB rate limited: '.$path, 0, $e);
        } catch (GuzzleException $e) {
            throw new SourceException('TMDB request failed: '.$path, 0, $e);
        }

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /** Genre id => name for one kind (movie | tv), kept for a week. */
    public function genres(string $kind): array
    {
        return $this->cache->remember("marquee.tmdb.genres.$kind.".$this->language(), 604800, function () use ($kind) {
            return array_column($this->get("genre/$kind/list")['genres'] ?? [], 'name', 'id');
        });
    }

    public static function year(?string $date): ?int
    {
        return $date ? (int) substr($date, 0, 4) ?: null : null;
    }

    public static function score(?float $average): ?int
    {
        return $average ? (int) round($average * 10) : null;
    }

    /** An image path as TMDB gives it ("/abc123.jpg"), or null. */
    public static function path(?string $path): ?string
    {
        return $path && preg_match('#^/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp|svg)$#', $path) ? $path : null;
    }

    /** A YouTube video id, or null. */
    public static function youtube(?string $id): ?string
    {
        return $id && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id) ? $id : null;
    }

    public static function people(array $list, int $limit, callable $role): array
    {
        return array_map(fn ($p) => [
            'name' => $p['name'],
            'role' => $role($p),
            'photo' => self::path($p['profile_path'] ?? null),
        ], array_slice($list, 0, $limit));
    }

    public static function trailer(array $videos): ?string
    {
        foreach ($videos['results'] ?? [] as $v) {
            if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer') {
                return self::youtube($v['key'] ?? null);
            }
        }

        return null;
    }

    /** Crew names for the given jobs, without repeats, in billing order. */
    public static function crew(array $crew, array $jobs): array
    {
        $names = [];
        foreach ($crew as $c) {
            if (in_array($c['job'] ?? '', $jobs, true)) {
                $names[$c['name']] = true;
            }
        }

        return array_keys($names);
    }
}

<?php

namespace Ernestdefoe\Marquee\Wikimedia;

use Flarum\Http\UrlGenerator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Finds the Wikipedia article about something another database knows by an id,
 * in any language, without a key: the id → the Wikidata item that holds it as a
 * statement (e.g. P5794 = IGDB slug) → that item's link to <lang>wiki → the
 * article's summary from Wikipedia's REST API.
 *
 * Nothing here is Marquee-specific, so it can move to a shared package.
 */
class Bridge
{
    private ?Client $http = null;

    public function __construct(private UrlGenerator $url)
    {
    }

    /** The Wikidata item (Q123) holding $property = $value, or null when none does. */
    public function item(string $property, string $value): ?string
    {
        // The value goes into a search query: no quotes or spaces that could add search terms.
        if (! preg_match('/^P\d{1,9}$/', $property) || ! preg_match('/^[^\s"]{1,200}$/u', $value)) {
            return null;
        }

        $found = $this->get('https://www.wikidata.org/w/api.php', [
            'action' => 'query', 'list' => 'search', 'srsearch' => "haswbstatement:$property=$value",
            'srlimit' => 1, 'srprop' => '', 'format' => 'json', 'formatversion' => 2,
        ]);
        $item = $found['query']['search'][0]['title'] ?? null;

        return is_string($item) && preg_match('/^Q\d{1,12}$/', $item) ? $item : null;
    }

    /**
     * The lead of the item's article on <wiki>.wikipedia.org, or null when that
     * Wikipedia has no article about it.
     *
     * @param string $language the full language (zh-TW) so Chinese comes in the right script
     * @return array{title: string, extract: string, url: string}|null
     */
    public function summary(string $item, string $wiki, string $language): ?array
    {
        if (! preg_match('/^Q\d{1,12}$/', $item) || ! preg_match('/^[a-z]{2,3}$/', $wiki)) {
            return null;
        }

        $entity = $this->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetentities', 'ids' => $item, 'props' => 'sitelinks', 'sitefilter' => $wiki.'wiki',
            'format' => 'json', 'formatversion' => 2,
        ]);
        $title = $entity['entities'][$item]['sitelinks'][$wiki.'wiki']['title'] ?? null;
        if (! is_string($title) || $title === '') {
            return null;
        }

        $page = $this->get(
            "https://$wiki.wikipedia.org/api/rest_v1/page/summary/".rawurlencode(str_replace(' ', '_', $title)),
            [],
            ['Accept-Language' => $language]
        );
        $extract = trim((string) ($page['extract'] ?? ''));
        $url = (string) ($page['content_urls']['desktop']['page'] ?? '');

        // A disambiguation page or an empty stub is no description.
        if (($page['type'] ?? '') !== 'standard' || $extract === '' || ! str_starts_with($url, "https://$wiki.wikipedia.org/wiki/")) {
            return null;
        }

        return ['title' => (string) ($page['title'] ?? $title), 'extract' => $extract, 'url' => $url];
    }

    /** Wikipedia's subdomain for a language code (fr-FR → fr, nb → no), or null for one it cannot be. */
    public static function wiki(string $language): ?string
    {
        $base = strtolower(explode('-', $language)[0]);

        return match (true) {
            $base === 'nb' => 'no',
            (bool) preg_match('/^[a-z]{2,3}$/', $base) => $base,
            default => null,
        };
    }

    /** @throws RuntimeException when Wikimedia cannot be reached or answers with an error */
    private function get(string $url, array $query, array $headers = []): array
    {
        try {
            $response = $this->client()->get($url, ['query' => $query, 'headers' => $headers]);
        } catch (GuzzleException $e) {
            // A missing article is a 404 from the REST API, not a failure.
            if ($e->getCode() === 404) {
                return [];
            }
            throw new RuntimeException('Wikimedia request failed: '.$e->getCode(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);

        return is_array($body) ? $body : [];
    }

    protected function client(): Client
    {
        // Wikimedia asks every client to say who it is and how to reach them.
        return $this->http ??= new Client(['timeout' => 5, 'connect_timeout' => 3, 'allow_redirects' => ['max' => 3, 'protocols' => ['https']], 'headers' => [
            'User-Agent' => 'Marquee for Flarum (+https://github.com/ernestdefoe/marquee; '.$this->url->to('forum')->base().')',
        ]]);
    }
}

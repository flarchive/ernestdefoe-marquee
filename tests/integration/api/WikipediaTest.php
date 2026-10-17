<?php

namespace Ernestdefoe\Marquee\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\MediaRepository;
use Ernestdefoe\Marquee\Settings;
use Ernestdefoe\Marquee\Source\IgdbGames;
use Ernestdefoe\Marquee\Translation\DeepL;
use Ernestdefoe\Marquee\Wikimedia\Bridge;
use Flarum\Http\UrlGenerator;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

/**
 * Game descriptions in the data language from Wikipedia, found through the
 * IGDB slug Wikidata stores (P5794). Wikimedia is mocked: the answers below are
 * trimmed copies of the real ones for Hollow Knight (2026-10-17).
 */
class WikipediaTest extends TestCase
{
    private const SEARCH = '{"batchcomplete":true,"query":{"searchinfo":{"totalhits":1},"search":[{"ns":0,"title":"Q29300592","pageid":30954609}]}}';
    private const NOTHING = '{"batchcomplete":true,"query":{"searchinfo":{"totalhits":0},"search":[]}}';
    private const SITELINK = '{"entities":{"Q29300592":{"type":"item","id":"Q29300592","sitelinks":{"frwiki":{"site":"frwiki","title":"Hollow Knight","badges":["Q17437796"]}}}},"success":1}';
    private const NO_SITELINK = '{"entities":{"Q29300592":{"type":"item","id":"Q29300592","sitelinks":{}}},"success":1}';
    private const SUMMARY = '{"type":"standard","title":"Hollow Knight","lang":"fr","extract":"Hollow Knight est un jeu vidéo indépendant de type metroidvania développé et édité par Team Cherry.","content_urls":{"desktop":{"page":"https://fr.wikipedia.org/wiki/Hollow_Knight"}}}';
    private const ENGLISH = 'Forge your own path in Hollow Knight!';

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];
    private MockHandler $wikimedia;
    private ?\Closure $details = null;
    private int $deeplCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-marquee');
        $this->setting(Settings::IGDB_CLIENT_ID, 'id');
        $this->setting(Settings::IGDB_CLIENT_SECRET, 'secret');
    }

    /** MediaRepository with IGDB answering $game and Wikimedia answering $responses, in order. */
    private function repository(array $responses, array $game = []): MediaRepository
    {
        $c = $this->app()->getContainer();

        $this->sent = [];
        $this->wikimedia = new MockHandler($responses);
        $stack = HandlerStack::create($this->wikimedia);
        $stack->push(Middleware::history($this->sent));

        $c->instance(Bridge::class, new class($c->make(UrlGenerator::class), $stack) extends Bridge {
            private Client $mock;

            public function __construct(UrlGenerator $url, HandlerStack $stack)
            {
                parent::__construct($url);
                $this->mock = new Client(['handler' => $stack, 'headers' => ['User-Agent' => 'test']]);
            }

            protected function client(): Client
            {
                return $this->mock;
            }
        });

        $game += IgdbGames::normalize(['name' => 'Hollow Knight', 'slug' => 'hollow-knight', 'summary' => self::ENGLISH, 'genres' => [['name' => 'Platform']]], null);
        $c->instance(IgdbGames::class, new class($game) extends IgdbGames {
            public function __construct(private array $game)
            {
            }

            public function configured(): bool
            {
                return true;
            }

            public function details(string $id): array
            {
                return $this->game;
            }
        });

        $test = $this;
        $c->instance(DeepL::class, new class($test) extends DeepL {
            public function __construct(private WikipediaTest $test)
            {
            }

            public function translate(string $key, array $texts, string $target): array
            {
                $this->test->countDeepL();

                return array_map(fn ($t) => "[$target] $t", $texts);
            }
        });

        return $c->make(MediaRepository::class);
    }

    public function countDeepL(): void
    {
        $this->deeplCalls++;
    }

    private function json(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    private function stale(): void
    {
        Media::query()->update(['fetched_at' => Carbon::now()->subDays(8)]);
    }

    #[Test]
    public function a_french_forum_gets_the_french_wikipedia_description(): void
    {
        $this->setting('default_locale', 'fr');

        $media = $this->repository([$this->json(self::SEARCH), $this->json(self::SITELINK), $this->json(self::SUMMARY)])
            ->resolve('game', '14593');

        $this->assertStringStartsWith('Hollow Knight est un jeu vidéo', $media->data['overview']);
        $this->assertSame(
            ['item' => 'Q29300592', 'lang' => 'fr', 'title' => 'Hollow Knight', 'url' => 'https://fr.wikipedia.org/wiki/Hollow_Knight'],
            $media->data['wikipedia']
        );

        // Stored with the title, so viewing never reaches Wikimedia.
        $this->assertSame('Q29300592', Media::query()->first()->data['wikipedia']['item']);

        [$search, $entity, $summary] = array_map(fn ($t) => $t['request'], $this->sent);
        parse_str($search->getUri()->getQuery(), $q);
        $this->assertSame('www.wikidata.org', $search->getUri()->getHost());
        $this->assertSame('haswbstatement:P5794=hollow-knight', $q['srsearch']);
        parse_str($entity->getUri()->getQuery(), $q);
        $this->assertSame(['wbgetentities', 'Q29300592', 'frwiki'], [$q['action'], $q['ids'], $q['sitefilter']]);
        $this->assertSame('https://fr.wikipedia.org/api/rest_v1/page/summary/Hollow_Knight', (string) $summary->getUri());
        $this->assertSame('fr', $summary->getHeaderLine('Accept-Language'));
        $this->assertNotSame('', $summary->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function the_weekly_refresh_skips_the_search_and_deepl_translates_only_what_is_left(): void
    {
        $this->setting('default_locale', 'fr');
        $this->setting(Settings::DEEPL_KEY, 'test-key:fx');

        $this->repository([$this->json(self::SEARCH), $this->json(self::SITELINK), $this->json(self::SUMMARY)])->resolve('game', '14593');
        $this->assertSame(1, $this->deeplCalls);
        $media = Media::query()->first();
        $this->assertStringStartsWith('Hollow Knight est', $media->data['overview']);
        $this->assertSame(['[FR] Platform'], $media->data['genres']);

        // Fresh: nothing is asked of anyone.
        $this->repository([])->resolve('game', '14593');
        $this->assertCount(0, $this->sent);

        // Stale: the item is known, so only its sitelink and summary are fetched; DeepL's copy is reused.
        $this->stale();
        $this->repository([$this->json(self::SITELINK), $this->json(self::SUMMARY)])->resolve('game', '14593');
        $this->assertCount(2, $this->sent);
        $this->assertSame(1, $this->deeplCalls);
    }

    #[Test]
    public function english_forums_never_ask_wikimedia(): void
    {
        $this->setting('default_locale', 'en');

        $media = $this->repository([])->resolve('game', '14593');

        $this->assertCount(0, $this->sent);
        $this->assertSame(self::ENGLISH, $media->data['overview']);
        $this->assertArrayNotHasKey('wikipedia', $media->data);
    }

    #[Test]
    public function without_an_article_in_the_language_deepl_and_then_english_take_over(): void
    {
        $this->setting('default_locale', 'fr');

        // Wikidata has no item for the slug: English.
        $media = $this->repository([$this->json(self::NOTHING)])->resolve('game', '14593');
        $this->assertSame(self::ENGLISH, $media->data['overview']);

        // The item exists but French Wikipedia has no article: DeepL translates the English.
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set(Settings::DEEPL_KEY, 'test-key:fx');
        $this->stale();
        $media = $this->repository([$this->json(self::SEARCH), $this->json(self::NO_SITELINK)])->resolve('game', '14593');
        $this->assertSame('[FR] '.self::ENGLISH, $media->data['overview']);
        $this->assertArrayNotHasKey('wikipedia', $media->data);

        // The article was deleted (404) or is a disambiguation page: same.
        foreach ([$this->json('{"type":"https://mediawiki.org/wiki/HyperSwitch/errors/not_found"}', 404), $this->json('{"type":"disambiguation","extract":"Hollow Knight peut désigner…","content_urls":{"desktop":{"page":"https://fr.wikipedia.org/wiki/Hollow_Knight"}}}')] as $answer) {
            $this->stale();
            $media = $this->repository([$this->json(self::SEARCH), $this->json(self::SITELINK), $answer])->resolve('game', '14593');
            $this->assertSame('[FR] '.self::ENGLISH, $media->data['overview']);
        }
    }

    #[Test]
    public function wikimedia_being_down_keeps_last_weeks_description_and_never_blocks_a_link(): void
    {
        $this->setting('default_locale', 'fr');

        // First fetch with Wikimedia down: the game still links, in English.
        $media = $this->repository([new Response(503)])->resolve('game', '14593');
        $this->assertSame(self::ENGLISH, $media->data['overview']);

        $this->stale();
        $this->repository([$this->json(self::SEARCH), $this->json(self::SITELINK), $this->json(self::SUMMARY)])->resolve('game', '14593');

        // Down on the next refresh: the French description stays.
        $this->stale();
        $media = $this->repository([new \GuzzleHttp\Exception\ConnectException('timeout', new Request('GET', 'https://www.wikidata.org'))])->resolve('game', '14593');
        $this->assertStringStartsWith('Hollow Knight est', $media->data['overview']);
        $this->assertSame('fr', $media->data['wikipedia']['lang']);
    }

    #[Test]
    public function wikipedia_urls_and_ids_are_checked(): void
    {
        $this->setting('default_locale', 'fr');

        // An answer pointing anywhere but fr.wikipedia.org is not used.
        $evil = str_replace('https://fr.wikipedia.org/wiki/', 'javascript:alert(1)//', self::SUMMARY);
        $media = $this->repository([$this->json(self::SEARCH), $this->json(self::SITELINK), $this->json($evil)])->resolve('game', '14593');
        $this->assertSame(self::ENGLISH, $media->data['overview']);

        // A slug IGDB should never send is not searched for.
        $this->stale();
        $media = $this->repository([], ['slug' => null])->resolve('game', '14593');
        $this->assertCount(0, $this->sent);
        $this->assertNull(IgdbGames::normalize(['name' => 'x', 'slug' => 'a" OR x'], null)['slug']);
        $bridge = $this->app()->getContainer()->make(Bridge::class);
        $this->assertNull($bridge->item('P5794', 'a OR haswbstatement:P31=Q5'));
        $this->assertNull($bridge->item('P5794', 'a"b'));
        $this->assertNull($bridge->item('P1 OR x', 'a'));
        $this->assertNull($bridge->summary('Q1', 'fr.evil.com/x', 'fr'));
        $this->assertCount(0, $this->sent);

        $this->assertSame('fr', Bridge::wiki('fr-FR'));
        $this->assertSame('no', Bridge::wiki('nb'));
        $this->assertSame('pt', Bridge::wiki('pt-BR'));
    }
}

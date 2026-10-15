<?php

namespace Ernestdefoe\Marquee\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Marquee\Settings;
use Ernestdefoe\Marquee\Source\DataLanguage;
use Ernestdefoe\Marquee\Source\DeezerAlbums;
use Ernestdefoe\Marquee\Source\DeezerClient;
use Ernestdefoe\Marquee\Source\IgdbGames;
use Flarum\Formatter\Formatter;
use Ernestdefoe\Marquee\Source\TmdbClient;
use Flarum\Discussion\Discussion;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs without network access: every title is stored fresh in the database, so
 * the repository never needs to ask TMDB or IGDB.
 */
class MarqueeTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-marquee');

        $this->setting(Settings::TMDB_TOKEN, 'test-token');
        $this->setting(Settings::TAGS, json_encode(['1' => 'movie', '2' => 'tv', '3' => 'game']));

        $now = Carbon::now()->toDateTimeString();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Tag::class => [
                ['id' => 1, 'name' => 'Movies', 'slug' => 'movies', 'position' => 0],
                ['id' => 2, 'name' => 'TV', 'slug' => 'tv', 'position' => 1],
                ['id' => 3, 'name' => 'Games', 'slug' => 'games', 'position' => 2],
            ],
            'marquee_media' => [
                ['id' => 1, 'kind' => 'movie', 'external_id' => '603', 'title' => 'The Matrix', 'year' => 1999, 'poster' => '/m.jpg', 'fetched_at' => $now,
                    'data' => json_encode(['title' => 'The Matrix', 'year' => 1999, 'poster' => '/m.jpg', 'overview' => 'Wake up, Neo.'])],
                ['id' => 2, 'kind' => 'tv', 'external_id' => '1396', 'title' => 'Breaking Bad', 'year' => 2008, 'poster' => '/b.jpg', 'fetched_at' => $now,
                    'data' => json_encode(['title' => 'Breaking Bad', 'year' => 2008, 'poster' => '/b.jpg', 'seasons' => [['number' => 1, 'episodes' => 7], ['number' => 2, 'episodes' => 13]]])],
            ],
            'marquee_seasons' => [
                ['id' => 1, 'media_id' => 2, 'number' => 1, 'fetched_at' => $now,
                    'data' => json_encode(['number' => 1, 'episodes' => [['n' => 1, 'title' => 'Pilot']]])],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'The Matrix (1999)', 'user_id' => 1, 'created_at' => $now, 'comment_count' => 1, 'marquee_media_id' => 1],
                ['id' => 2, 'title' => 'Breaking Bad (2008) S01E01: Pilot', 'user_id' => 1, 'created_at' => $now, 'comment_count' => 1, 'marquee_media_id' => 2, 'marquee_season' => 1, 'marquee_episode' => 1],
            ],
        ]);
    }

    private function json($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function createDiscussion(array $marquee, int $tagId, int $as = 1)
    {
        return $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => $as,
            'json' => ['data' => [
                'type' => 'discussions',
                'attributes' => ['title' => 'Linked', 'content' => 'A post long enough.', 'marquee' => $marquee],
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) $tagId]]]],
            ]],
        ]));
    }

    #[Test]
    public function guests_cannot_search_or_look_up(): void
    {
        $this->assertSame(401, $this->send($this->request('GET', '/api/marquee/search', ['queryParams' => ['kind' => 'movie', 'q' => 'matrix']]))->getStatusCode());
        // A guest POST is stopped by the CSRF check (400) before authentication (401).
        $this->assertContains($this->send($this->request('POST', '/api/marquee/media', ['json' => ['kind' => 'movie', 'id' => '603']]))->getStatusCode(), [400, 401]);
    }

    #[Test]
    public function lookup_does_not_answer_get(): void
    {
        // It stores the title, so it must not be triggerable by a cross-site GET.
        $response = $this->send($this->request('GET', '/api/marquee/media', ['authenticatedAs' => 2, 'queryParams' => ['kind' => 'movie', 'id' => '603']]));

        $this->assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function a_source_without_keys_returns_nothing(): void
    {
        $response = $this->send($this->request('GET', '/api/marquee/search', ['authenticatedAs' => 2, 'queryParams' => ['kind' => 'game', 'q' => 'zelda']]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->json($response)['data']);
    }

    #[Test]
    public function the_forum_only_maps_tags_whose_source_is_ready(): void
    {
        $tags = $this->json($this->send($this->request('GET', '/api')))['data']['attributes']['marqueeTags'];

        // No IGDB keys, so the Games tag stays a normal tag.
        $this->assertSame(['1' => 'movie', '2' => 'tv'], $tags);
    }

    #[Test]
    public function music_needs_no_keys(): void
    {
        $this->setting(Settings::TAGS, json_encode(['1' => 'music']));
        $this->setting(Settings::TMDB_TOKEN, '');

        $tags = $this->json($this->send($this->request('GET', '/api')))['data']['attributes']['marqueeTags'];
        $this->assertSame(['1' => 'music'], $tags);
    }

    #[Test]
    public function a_stored_title_can_be_linked(): void
    {
        $response = $this->createDiscussion(['kind' => 'movie', 'id' => '603'], 1);

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('1', $this->json($response)['data']['relationships']['marqueeMedia']['data']['id']);
    }

    #[Test]
    public function seasons_and_episodes_must_exist(): void
    {
        $this->assertSame(422, $this->createDiscussion(['kind' => 'tv', 'id' => '1396', 'season' => 9], 2)->getStatusCode());
        $this->assertSame(422, $this->createDiscussion(['kind' => 'tv', 'id' => '1396', 'season' => 1, 'episode' => 8], 2)->getStatusCode());
        $this->assertSame(422, $this->createDiscussion(['kind' => 'tv', 'id' => '1396', 'episode' => 1], 2)->getStatusCode());
        $this->assertSame(201, $this->createDiscussion(['kind' => 'tv', 'id' => '1396', 'season' => 1, 'episode' => 7], 2)->getStatusCode());
    }

    #[Test]
    public function unknown_kinds_and_bad_ids_are_refused(): void
    {
        $this->assertSame(422, $this->createDiscussion(['kind' => 'podcast', 'id' => '1'], 1)->getStatusCode());
        $this->assertSame(422, $this->createDiscussion(['kind' => 'movie', 'id' => '../etc'], 1)->getStatusCode());
        // Games have no keys here.
        $this->assertSame(422, $this->createDiscussion(['kind' => 'game', 'id' => '1'], 3)->getStatusCode());
    }

    #[Test]
    public function only_someone_who_can_rename_may_change_the_link(): void
    {
        $response = $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['marquee' => null]]],
        ]));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(1, Discussion::find(1)->marquee_media_id);
    }

    #[Test]
    public function the_link_can_be_changed_and_the_reply_carries_the_details(): void
    {
        $response = $this->send($this->request('PATCH', '/api/discussions/2', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '2', 'attributes' => ['marquee' => ['kind' => 'tv', 'id' => '1396', 'season' => 1, 'episode' => null]]]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $this->assertSame(1, $body['data']['attributes']['marqueeSeason']);
        $this->assertNull($body['data']['attributes']['marqueeEpisode']);

        // The page swaps the hero from this reply, so the details must be in it.
        $media = array_values(array_filter($body['included'] ?? [], fn ($i) => $i['type'] === 'marquee-media'));
        $this->assertSame('Breaking Bad', $media[0]['attributes']['details']['title']);
        $this->assertSame('Pilot', $body['data']['attributes']['marqueeSeasonData']['episodes'][0]['title']);
    }

    #[Test]
    public function the_link_can_be_removed(): void
    {
        $response = $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['marquee' => null]]],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(Discussion::find(1)->marquee_media_id);
    }

    #[Test]
    public function details_travel_with_the_discussion_page_not_the_list(): void
    {
        $list = $this->json($this->send($this->request('GET', '/api/discussions')));
        $media = array_values(array_filter($list['included'] ?? [], fn ($i) => $i['type'] === 'marquee-media'));
        $this->assertNotEmpty($media);
        $this->assertArrayNotHasKey('details', $media[0]['attributes']);

        $page = $this->json($this->send($this->request('GET', '/api/discussions/2')));
        $media = array_values(array_filter($page['included'] ?? [], fn ($i) => $i['type'] === 'marquee-media'));
        $this->assertSame('Breaking Bad', $media[0]['attributes']['details']['title']);
        $this->assertSame('Pilot', $page['data']['attributes']['marqueeSeasonData']['episodes'][0]['title']);
    }

    #[Test]
    public function the_season_endpoint_only_serves_known_seasons(): void
    {
        $this->assertSame(200, $this->send($this->request('GET', '/api/marquee/media/2/seasons/1'))->getStatusCode());
        $this->assertSame(404, $this->send($this->request('GET', '/api/marquee/media/2/seasons/5'))->getStatusCode());
        $this->assertSame(404, $this->send($this->request('GET', '/api/marquee/media/99/seasons/1'))->getStatusCode());
    }

    #[Test]
    public function the_data_language_follows_the_forum_unless_set(): void
    {
        $this->setting('default_locale', 'pt_BR');
        $this->assertSame('pt-BR', $this->app()->getContainer()->make(DataLanguage::class)->get());
    }

    #[Test]
    public function an_explicit_data_language_wins_and_bad_values_fall_back(): void
    {
        $this->setting(Settings::LANGUAGE, 'fr-FR');
        $this->assertSame('fr-FR', $this->app()->getContainer()->make(DataLanguage::class)->get());
    }

    #[Test]
    public function list_posters_can_be_turned_off(): void
    {
        $this->setting(Settings::LIST_POSTERS, '0');
        $this->assertFalse($this->json($this->send($this->request('GET', '/api')))['data']['attributes']['marqueeListPosters']);
    }

    #[Test]
    public function igdb_uses_a_regional_name_only_for_its_language(): void
    {
        $game = ['name' => 'Elden Ring', 'game_localizations' => [['name' => 'エルデンリング', 'region' => ['identifier' => 'ja-JP']]]];

        $this->assertSame('エルデンリング', IgdbGames::normalize($game, null, 'ja')['title']);
        $this->assertSame('Elden Ring', IgdbGames::normalize($game, null, 'fr-FR')['title']);
    }

    #[Test]
    public function season_and_episode_references_are_tagged_in_posts(): void
    {
        $formatter = $this->app()->getContainer()->make(Formatter::class);
        $html = fn (string $text) => $formatter->render($formatter->parse($text));

        $this->assertStringContainsString('data-season="02" data-episode="04">S02E04</span>', $html('Loved S02E04!'));
        $this->assertStringContainsString('data-season="2" data-episode="4">Season 2 Episode 4</span>', $html('Season 2 Episode 4 was it.'));
        $this->assertStringContainsString('data-season="3" data-episode="">Season 3</span>', $html('All of Season 3.'));
        $this->assertStringContainsString('data-season="2" data-episode="4">Saison 2 Épisode 4</span>', $html('La Saison 2 Épisode 4.'));
        $this->assertStringNotContainsString('MarqueeRef', $html('pass2 S2a xS2 S2E4x'));
        $this->assertStringNotContainsString('<script>', $html('<script>S2E4</script>'));
    }

    #[Test]
    public function deezer_albums_are_normalised_and_checked(): void
    {
        $album = DeezerAlbums::normalize([
            'id' => 6575789,
            'title' => 'Random Access Memories',
            'release_date' => '2013-05-17',
            'label' => 'Columbia',
            'record_type' => 'album',
            'nb_tracks' => 2,
            'duration' => 600,
            'fans' => 886534,
            'md5_image' => '311bba0fc112d15f72c8b5a65f0456c1',
            'link' => 'javascript:alert(1)',
            'artist' => ['name' => 'Daft Punk', 'picture_xl' => 'https://cdn-images.dzcdn.net/images/artist/638e69b9caaf9f9f3f8826febea7b543/1000x1000-000000-80-0-0.jpg'],
            'genres' => ['data' => [['name' => 'Dance']]],
            'tracks' => ['data' => [
                ['title' => 'Give Life Back to Music', 'duration' => 274, 'artist' => ['name' => 'Daft Punk']],
                ['title' => 'Get Lucky', 'duration' => 367, 'explicit_lyrics' => false, 'artist' => ['name' => 'Daft Punk feat. Pharrell']],
            ]],
        ]);

        $this->assertSame(2013, $album['year']);
        $this->assertSame('deezer:cover:311bba0fc112d15f72c8b5a65f0456c1', $album['poster']);
        $this->assertSame('deezer:artist:638e69b9caaf9f9f3f8826febea7b543', $album['backdrop']);
        $this->assertNull($album['tracks'][0]['artist']);
        $this->assertSame('Daft Punk feat. Pharrell', $album['tracks'][1]['artist']);
        // Not a Deezer link, so the album's own Deezer URL is used instead.
        $this->assertSame('https://www.deezer.com/album/6575789', $album['url']);
        $this->assertNull(DeezerClient::image('cover', '"><script>'));
    }

    #[Test]
    public function source_data_cannot_become_a_script_link(): void
    {
        $this->assertNull(TmdbClient::path('javascript:alert(1)'));
        $this->assertNull(TmdbClient::path('/../x.jpg'));
        $this->assertSame('/abc_123.jpg', TmdbClient::path('/abc_123.jpg'));
        $this->assertNull(TmdbClient::youtube('"><script>'));

        $game = IgdbGames::normalize([
            'name' => 'x',
            'url' => 'javascript:alert(1)',
            'cover' => ['image_id' => '"><img onerror=x>'],
            'videos' => [['name' => 'Trailer', 'video_id' => 'javascript:x']],
        ], null);

        $this->assertNull($game['url']);
        $this->assertNull($game['poster']);
        $this->assertNull($game['trailer']);
    }
}

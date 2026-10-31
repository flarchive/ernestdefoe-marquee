<?php

namespace Ernestdefoe\Marquee\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\MediaRepository;
use Ernestdefoe\Marquee\Season;
use Ernestdefoe\Marquee\Settings;
use Ernestdefoe\Marquee\Source\DataLanguage;
use Ernestdefoe\Marquee\Source\DeezerAlbums;
use Ernestdefoe\Marquee\Source\DeezerClient;
use Ernestdefoe\Marquee\Source\OpenLibraryBooks;
use Ernestdefoe\Marquee\Source\OpenLibraryClient;
use Ernestdefoe\Marquee\Translation\DeepL;
use Ernestdefoe\Marquee\Translation\Translator;
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
        $this->setting(Settings::TAGS, json_encode(['1' => 'music', '2' => 'book']));
        $this->setting(Settings::TMDB_TOKEN, '');

        $tags = $this->json($this->send($this->request('GET', '/api')))['data']['attributes']['marqueeTags'];
        $this->assertSame(['1' => 'music', '2' => 'book'], $tags);
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

    private function ownDiscussion(): void
    {
        $this->prepareDatabase([Discussion::class => [
            ['id' => 3, 'title' => 'Mine', 'user_id' => 2, 'created_at' => Carbon::now()->toDateTimeString(), 'comment_count' => 1, 'marquee_media_id' => 1],
        ]]);
    }

    private function unlinkOwn()
    {
        return $this->send($this->request('PATCH', '/api/discussions/3', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'id' => '3', 'attributes' => ['marquee' => null]]],
        ]));
    }

    #[Test]
    public function members_may_change_the_link_on_their_own_discussion_by_default(): void
    {
        // Members have "linkOwn" from the migration: an author who may rename may relink, as before.
        $this->ownDiscussion();

        $this->assertSame(200, $this->unlinkOwn()->getStatusCode());
        $this->assertNull(Discussion::find(3)->marquee_media_id);
    }

    #[Test]
    public function without_link_own_renaming_no_longer_lets_an_author_change_the_link(): void
    {
        // Taken from members (Wlork, Discuss d/40077 #66): only staff who may rename any discussion.
        $this->ownDiscussion();
        $this->database()->table('group_permission')->where('permission', 'ernestdefoe-marquee.linkOwn')->delete();

        $page = $this->json($this->send($this->request('GET', '/api/discussions/3', ['authenticatedAs' => 2])));
        $this->assertFalse($page['data']['attributes']['canMarqueeLink']);
        $this->assertTrue($page['data']['attributes']['canRename'], 'renaming itself is untouched');
        $this->assertSame(403, $this->unlinkOwn()->getStatusCode());
        $this->assertSame(1, Discussion::find(3)->marquee_media_id);
    }

    private function unlinkAsModerator()
    {
        // Moderators may rename any discussion; the migration copied that to the link.
        $this->prepareDatabase(['group_user' => [['user_id' => 2, 'group_id' => 4]]]);

        return fn () => $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['marquee' => null]]],
        ]));
    }

    #[Test]
    public function groups_that_could_rename_keep_changing_any_link(): void
    {
        $unlink = $this->unlinkAsModerator();

        $this->assertSame(200, $unlink()->getStatusCode());
        $this->assertNull(Discussion::find(1)->marquee_media_id);
    }

    #[Test]
    public function renaming_any_discussion_no_longer_brings_the_link_with_it(): void
    {
        // Wlork, Discuss d/40077 #69: moderators may rename, only admins may relink.
        $unlink = $this->unlinkAsModerator();
        $this->database()->table('group_permission')->where('permission', 'discussion.marqueeLink')->delete();

        $this->assertSame(403, $unlink()->getStatusCode());
        $this->assertSame(1, Discussion::find(1)->marquee_media_id);
    }

    private function relate(array $related, int $as = 1, int $discussion = 1, array $attributes = [])
    {
        return $this->send($this->request('PATCH', '/api/discussions/'.$discussion, [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion, 'attributes' => $attributes + ['marqueeRelated' => $related]]],
        ]));
    }

    #[Test]
    public function related_titles_are_kept_in_order_and_come_with_the_page_only(): void
    {
        // Wlork, Discuss d/40077 #66: one discussion for a series, the other games picked by hand.
        $response = $this->relate([['kind' => 'tv', 'id' => '1396'], ['kind' => 'movie', 'id' => '603'], ['kind' => 'tv', 'id' => '1396']]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // The linked title itself and repeats are dropped.
        $this->assertSame([2], Discussion::find(1)->marquee_related);

        $page = $this->json($this->send($this->request('GET', '/api/discussions/1', ['authenticatedAs' => 2])));
        $this->assertSame([['type' => 'marquee-media', 'id' => '2']], $page['data']['relationships']['marqueeRelatedMedia']['data']);
        $media = array_values(array_filter($page['included'], fn ($i) => $i['type'] === 'marquee-media' && $i['id'] === '2'));
        $this->assertSame('Breaking Bad', $media[0]['attributes']['details']['title']);

        $list = $this->json($this->send($this->request('GET', '/api/discussions', ['authenticatedAs' => 2])));
        $this->assertArrayNotHasKey('marqueeRelatedMedia', $list['data'][0]['relationships'] ?? []);

        $this->assertSame(200, $this->relate([])->getStatusCode());
        $this->assertNull(Discussion::find(1)->marquee_related);
    }

    #[Test]
    public function related_titles_need_the_link_permission_and_a_linked_title(): void
    {
        $this->assertSame(403, $this->relate([['kind' => 'tv', 'id' => '1396']], 2)->getStatusCode());

        // Unlinked: nothing to relate to.
        $this->assertSame(200, $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['marquee' => null]]],
        ]))->getStatusCode());
        $this->assertSame(422, $this->relate([['kind' => 'tv', 'id' => '1396']])->getStatusCode());
    }

    #[Test]
    public function relinking_to_a_related_title_takes_it_out_of_the_related_ones(): void
    {
        $this->relate([['kind' => 'tv', 'id' => '1396']], 1, 1);
        $this->assertSame([2], Discussion::find(1)->marquee_related);

        $response = $this->relate([['kind' => 'tv', 'id' => '1396'], ['kind' => 'movie', 'id' => '603']], 1, 1, ['marquee' => ['kind' => 'tv', 'id' => '1396', 'season' => null, 'episode' => null]]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(2, Discussion::find(1)->marquee_media_id);
        $this->assertSame([1], Discussion::find(1)->marquee_related);
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
    public function a_title_nothing_links_to_any_more_is_deleted(): void
    {
        // Wlork, Discuss d/40077 #88: removing the link left the title in marquee_media.
        // Discussion 2 lists The Matrix as related, so the title stays until that goes too.
        $this->relate([['kind' => 'movie', 'id' => '603']], 1, 2);
        $this->send($this->request('PATCH', '/api/discussions/1', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['marquee' => null]]],
        ]));
        $this->assertNotNull(Media::find(1));

        $this->assertSame(200, $this->relate([], 1, 2)->getStatusCode());
        $this->assertNull(Media::find(1));
    }

    #[Test]
    public function deleting_a_discussion_deletes_its_titles_and_seasons(): void
    {
        $response = $this->send($this->request('DELETE', '/api/discussions/2', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $this->assertNull(Media::find(2));
        $this->assertSame(0, Season::query()->where('media_id', 2)->count());
        $this->assertNotNull(Media::find(1));
    }

    #[Test]
    public function purging_takes_the_settings_with_it(): void
    {
        // Wlork, Discuss d/40077 #88.
        $this->setting('ernestdefoe-marquee.language', 'fr-FR');
        $this->setting('other-extension.key', 'kept');
        $db = $this->app()->getContainer()->make('db.connection');
        $migration = require __DIR__.'/../../../migrations/2026_10_31_000001_delete_marquee_settings_on_purge.php';

        $migration['down']($db->getSchemaBuilder());

        $this->assertSame(0, $db->table('settings')->where('key', 'like', 'ernestdefoe-marquee.%')->count());
        $this->assertSame('kept', $db->table('settings')->where('key', 'other-extension.key')->value('value'));
    }

    #[Test]
    public function the_daily_prune_deletes_look_ups_that_were_never_posted(): void
    {
        $repository = $this->app()->getContainer()->make(MediaRepository::class);
        $title = fn (int $id, Carbon $at) => Media::query()->insert(['id' => $id, 'kind' => 'movie', 'external_id' => (string) (900 + $id), 'title' => "Look-up $id", 'fetched_at' => $at, 'data' => '{}']);
        $title(3, Carbon::now()->subDays(2));
        $title(4, Carbon::now());
        Media::query()->whereKey(1)->update(['fetched_at' => Carbon::now()->subDays(9)]);

        $this->assertSame(1, $repository->prune());

        // The one-day-old look-up may be in a composer still; linked titles stay however old.
        $this->assertSame([1, 2, 4], Media::query()->orderBy('id')->pluck('id')->all());
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
    public function open_library_books_are_normalised_and_checked(): void
    {
        $doc = [
            'key' => '/works/OL893461W',
            'title' => 'Dune Messiah',
            'author_name' => ['Frank Herbert', 'Someone Else'],
            'first_publish_year' => 1969,
            'cover_i' => 980253,
            'edition_count' => 101,
            'number_of_pages_median' => 279,
            'ratings_average' => 3.937063,
            'ratings_count' => 143,
            'want_to_read_count' => 575,
            'subject' => ['Science Fiction', 'science fiction', 'Translations into Russian', 'nyt:hardcover-fiction=2021', 'Dune (Imaginary place)', 'Accessible book', 'Fiction'],
            'editions' => ['docs' => [[
                'title' => 'Le Messie de Dune',
                'language' => ['fre'],
                'cover_i' => 974774,
                'publisher' => ['Robert Laffont'],
                'publish_date' => ['January 1, 1975'],
                'isbn' => ['2221001648', '9782221001646'],
            ]]],
        ];
        $work = [
            'title' => 'Dune Messiah',
            'description' => ['type' => '/type/text', 'value' => "**Book Two** of [the saga](https://example.com).\r\n\r\nPaul rules. ([source][1])\r\n\r\n(Source: the publisher)\r\n\r\n----------\r\n[1]: https://example.com"],
        ];
        $authors = [['name' => 'Frank Herbert', 'birth_date' => '8 October 1920', 'death_date' => '1986', 'photos' => [14852808]]];

        $fr = OpenLibraryBooks::normalize('OL893461W', $doc, $work, $authors, 'fr');
        $this->assertSame('Le Messie de Dune', $fr['title']);
        $this->assertSame('Dune Messiah', $fr['originalTitle']);
        $this->assertSame('ol:b:974774', $fr['poster']);
        $this->assertSame('9782221001646', $fr['isbn']);
        $this->assertSame('Robert Laffont', $fr['publisher']);
        $this->assertSame("Book Two of the saga.\n\nPaul rules.", $fr['overview']);
        $this->assertSame(['Science Fiction', 'Fiction'], $fr['subjects']);
        $this->assertSame(3.9, $fr['rating']);
        $this->assertSame(['name' => 'Frank Herbert', 'role' => '1920–1986', 'photo' => 'ol:a:14852808'], $fr['people'][0]);
        $this->assertSame(['name' => 'Someone Else', 'role' => null, 'photo' => null], $fr['people'][1]);
        $this->assertSame('https://openlibrary.org/works/OL893461W', $fr['url']);

        // The French edition is not used on an English forum.
        $en = OpenLibraryBooks::normalize('OL893461W', $doc, $work, $authors, 'en');
        $this->assertSame('Dune Messiah', $en['title']);
        $this->assertNull($en['originalTitle']);
        $this->assertSame('ol:b:980253', $en['poster']);
        $this->assertSame(279, $en['pages']);
        $this->assertNull($en['isbn']);

        // An edition in the work's own language keeps the work's casing, but lends its cover and publisher.
        $doc['editions']['docs'][0] = ['title' => 'Dune messiah', 'subtitle' => 'book two', 'language' => ['eng'], 'cover_i' => 1, 'publisher' => ['Berkley'], 'publish_date' => ['xxxx']];
        $same = OpenLibraryBooks::normalize('OL893461W', $doc, $work, $authors, 'en');
        $this->assertSame('Dune Messiah', $same['title']);
        $this->assertNull($same['subtitle']);
        $this->assertSame('ol:b:1', $same['poster']);
        $this->assertSame('Berkley', $same['publisher']);
        $this->assertNull($same['published']);

        // So does an edition that only adds to the work's title (Open Library's pick for Klara and the Sun).
        $klara = ['key' => '/works/OL20883297W', 'title' => 'Klara and the Sun', 'editions' => ['docs' => [['title' => 'Klara and the Sun * Christmas EDI', 'language' => ['eng']]]]];
        $extra = OpenLibraryBooks::normalize('OL20883297W', $klara, ['title' => 'Klara and the Sun'], [], 'en');
        $this->assertSame('Klara and the Sun', $extra['title']);
        $this->assertNull($extra['originalTitle']);

        $this->assertNull(OpenLibraryClient::image('b', '"><script>'));
        $this->assertNull(OpenLibraryClient::image('b', -1));
    }

    #[Test]
    public function book_ids_are_checked_before_any_request(): void
    {
        $this->expectException(\Ernestdefoe\Marquee\Source\SourceException::class);
        $this->app()->getContainer()->make(OpenLibraryBooks::class)->details('../../admin');
    }

    #[Test]
    public function deepl_target_codes_follow_the_data_language(): void
    {
        $this->assertNull(DeepL::target('en-US'));
        $this->assertSame('FR', DeepL::target('fr-FR'));
        $this->assertSame('DE', DeepL::target('de'));
        $this->assertSame('PT-BR', DeepL::target('pt-BR'));
        $this->assertSame('PT-PT', DeepL::target('pt'));
        $this->assertSame('ZH-HANS', DeepL::target('zh-CN'));
        $this->assertSame('ZH-HANT', DeepL::target('zh-TW'));
        $this->assertSame('NB', DeepL::target('no'));
    }

    #[Test]
    public function game_text_is_translated_once_and_reused_while_the_english_is_unchanged(): void
    {
        $this->setting(Settings::LANGUAGE, 'fr');
        $this->setting(Settings::DEEPL_KEY, 'test-key:fx');

        $deepl = new class extends DeepL {
            public int $calls = 0;
            public bool $fail = false;

            public function translate(string $key, array $texts, string $target): array
            {
                $this->calls++;
                if ($this->fail) {
                    throw new \RuntimeException('quota');
                }

                return array_map(fn ($t) => "[$target] $t", $texts);
            }
        };
        $c = $this->app()->getContainer();
        $translator = new Translator($deepl, $c->make(\Ernestdefoe\Marquee\Source\DataLanguage::class), $c->make(\Flarum\Settings\SettingsRepositoryInterface::class), new \Psr\Log\NullLogger());

        $game = ['title' => 'Hades', 'overview' => 'Defy the god of the dead.', 'genres' => ['Role-playing (RPG)', ''], 'modes' => ['Single player'], 'platforms' => ['PC']];
        $fr = $translator->apply('game', $game, null);

        $this->assertSame(1, $deepl->calls);
        $this->assertSame('[FR] Defy the god of the dead.', $fr['overview']);
        // Genres and modes are IGDB's fixed list, translated by the forum's language files instead.
        $this->assertSame(['Role-playing (RPG)', ''], $fr['genres']);
        $this->assertSame(['Single player'], $fr['modes']);
        $this->assertSame(['PC'], $fr['platforms']);
        $this->assertSame('Hades', $fr['title']);
        $this->assertSame('deepl', $fr['translation']['by']);

        // A refresh with the same English reuses the stored translation: no second call.
        $again = $translator->apply('game', $game, $fr);
        $this->assertSame(1, $deepl->calls);
        $this->assertSame($fr['overview'], $again['overview']);

        // New English is translated again.
        $changed = $translator->apply('game', ['overview' => 'New text.'] + $game, $fr);
        $this->assertSame(2, $deepl->calls);
        $this->assertSame('[FR] New text.', $changed['overview']);

        // DeepL failing leaves the English: linking still works.
        $deepl->fail = true;
        $failed = $translator->apply('game', ['overview' => 'Other text.'] + $game, $fr);
        $this->assertSame('Other text.', $failed['overview']);
        $this->assertArrayNotHasKey('translation', $failed);

        // Movies come translated from TMDB already (English forums: DeepL::target() is null).
        $deepl->fail = false;
        $this->assertSame($game, $translator->apply('movie', $game, null));
        $this->assertSame(3, $deepl->calls);
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

    #[Test]
    public function genres_and_modes_keep_igdbs_slugs_for_the_language_files(): void
    {
        $game = IgdbGames::normalize([
            'name' => 'Hades',
            'genres' => [['name' => 'Role-playing (RPG)', 'slug' => 'role-playing-rpg'], ['name' => 'Indie', 'slug' => '"><x']],
            'game_modes' => [['name' => 'Single player', 'slug' => 'single-player']],
        ], null);

        $this->assertSame(['Role-playing (RPG)', 'Indie'], $game['genres']);
        $this->assertSame(['role-playing-rpg', null], $game['genreSlugs']);
        $this->assertSame(['single-player'], $game['modeSlugs']);
    }

    #[Test]
    public function the_year_can_be_left_out_of_titles(): void
    {
        $this->setting(Settings::TITLE_YEAR, '0');

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true)['data']['attributes'];

        $this->assertFalse($forum['marqueeTitleYear']);
    }

    #[Test]
    public function the_display_settings_reach_the_forum_as_scalars_only(): void
    {
        $this->setting(Settings::SHOW_SCORES, '0');
        $this->setting(Settings::LIST_META, '<script>');

        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true)['data']['attributes'];

        $this->assertFalse($forum['marqueeScores']);
        $this->assertTrue($forum['marqueeSourceLink']);
        $this->assertSame('kind_year', $forum['marqueeListMeta']);
        $this->assertTrue($forum['marqueeTitleYear']);
        $this->assertArrayNotHasKey('ernestdefoe-marquee.deepl_key', $forum);
    }
}

<?php

use Ernestdefoe\Marquee\Api;
use Ernestdefoe\Marquee\Console;
use Ernestdefoe\Marquee\Formatter;
use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\MediaRepository;
use Ernestdefoe\Marquee\Settings;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Formatter())
        ->configure(Formatter\EpisodeReferences::class),

    (new Extend\Routes('api'))
        ->get('/marquee/search', 'marquee.search', Api\SearchController::class)
        ->post('/marquee/media', 'marquee.lookup', Api\LookupController::class)
        ->get('/marquee/media/{id:\d+}/seasons/{number:\d+}', 'marquee.season', Api\SeasonController::class),

    (new Extend\Model(Discussion::class))
        ->belongsTo('marqueeMedia', Media::class, 'marquee_media_id')
        ->cast('marquee_media_id', 'integer')
        ->cast('marquee_season', 'integer')
        ->cast('marquee_episode', 'integer')
        ->cast('marquee_related', 'array'),

    new Extend\ApiResource(Api\MediaResource::class),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class)
        ->endpoint(
            [Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class],
            fn (Endpoint\Index|Endpoint\Show|Endpoint\Create|Endpoint\Update $endpoint) => $endpoint->addDefaultInclude(['marqueeMedia'])
        )
        // The related titles' cards: the discussion page only, never the list.
        ->endpoint(
            [Endpoint\Show::class, Endpoint\Update::class],
            fn (Endpoint\Show|Endpoint\Update $endpoint) => $endpoint->addDefaultInclude(['marqueeRelatedMedia'])
        ),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\Console())
        ->command(Console\DescribeCommand::class)
        ->schedule(Console\DescribeCommand::class, function (Event $event): void {
            $event->daily()->withoutOverlapping();
        })
        ->command(Console\GameTypesCommand::class)
        ->schedule(Console\GameTypesCommand::class, function (Event $event): void {
            $event->daily()->withoutOverlapping();
        })
        ->command(Console\PruneCommand::class)
        ->schedule(Console\PruneCommand::class, function (Event $event): void {
            $event->daily()->withoutOverlapping();
        }),

    // A deleted discussion's titles go once nothing else links to them.
    (new Extend\Event())
        ->listen(Deleted::class, function (Deleted $event): void {
            resolve(MediaRepository::class)->prune([$event->discussion->marquee_media_id, ...(array) ($event->discussion->marquee_related ?? [])]);
        }),

    (new Extend\Settings())
        ->default(Settings::LANGUAGE, '')
        ->default(Settings::LIST_POSTERS, true)
        ->default(Settings::SHOW_SCORES, true)
        ->default(Settings::SHOW_SOURCE_LINK, true)
        ->default(Settings::LIST_META, 'kind_year')
        ->default(Settings::TITLE_YEAR, true)
        ->default(Settings::TAGS, '{}'),
];

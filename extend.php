<?php

use Ernestdefoe\Marquee\Api;
use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\Settings;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Discussion\Discussion;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/marquee/search', 'marquee.search', Api\SearchController::class)
        ->post('/marquee/media', 'marquee.lookup', Api\LookupController::class)
        ->get('/marquee/media/{id:\d+}/seasons/{number:\d+}', 'marquee.season', Api\SeasonController::class),

    (new Extend\Model(Discussion::class))
        ->belongsTo('marqueeMedia', Media::class, 'marquee_media_id')
        ->cast('marquee_media_id', 'integer')
        ->cast('marquee_season', 'integer')
        ->cast('marquee_episode', 'integer'),

    new Extend\ApiResource(Api\MediaResource::class),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class)
        ->endpoint(
            [Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class],
            fn (Endpoint\Index|Endpoint\Show|Endpoint\Create|Endpoint\Update $endpoint) => $endpoint->addDefaultInclude(['marqueeMedia'])
        ),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\Settings())
        ->default(Settings::LANGUAGE, 'en-US')
        ->default(Settings::TAGS, '{}'),
];

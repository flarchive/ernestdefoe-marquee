<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\Media;
use Flarum\Api\Context;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;

/**
 * A linked title as it travels with a discussion. Lists get the summary (title,
 * year, poster); the discussion page also gets the full details for the hero.
 */
class MediaResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'marquee-media';
    }

    public function model(): string
    {
        return Media::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('kind'),
            Schema\Str::make('externalId')->get(fn (Media $m) => $m->external_id),
            Schema\Str::make('title'),
            Schema\Integer::make('year')->nullable(),
            Schema\Str::make('poster')->nullable(),
            // Albums only: lists show "Artist – Album".
            Schema\Str::make('artist')->nullable()->get(fn (Media $m) => $m->data['artist'] ?? null),
            Schema\Arr::make('details')
                ->visible(fn (Media $m, Context $context) => $context->showing(DiscussionResource::class) || $context->updating(DiscussionResource::class))
                ->get(fn (Media $m) => $m->data),
        ];
    }
}

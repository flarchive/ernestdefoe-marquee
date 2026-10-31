<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\Source\DataLanguage;
use Ernestdefoe\Marquee\Wikimedia\Bridge;
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

    /**
     * A game or book whose description is still the source's English on a
     * non-English forum (no Wikipedia article, no DeepL): the card says so and
     * keeps the English behind a toggle.
     *
     * @return array{untranslated?: array{have: string, want: string}}
     */
    private static function untranslated(Media $m): array
    {
        $d = $m->data;
        $language = resolve(DataLanguage::class)->get();
        $wiki = Bridge::wiki($language);

        if (! in_array($m->kind, ['game', 'book'], true) || $wiki === null || $wiki === 'en'
            || ! is_string($d['overview'] ?? null) || trim($d['overview']) === '' || isset($d['wikipedia']) || isset($d['translation'])) {
            return [];
        }

        return ['untranslated' => ['have' => 'en', 'want' => $language]];
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
                ->get(fn (Media $m) => $m->data + self::untranslated($m)),
        ];
    }
}

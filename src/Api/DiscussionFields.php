<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\MediaRepository;
use Ernestdefoe\Marquee\Season;
use Ernestdefoe\Marquee\Source\SourceException;
use Ernestdefoe\Marquee\Source\Sources;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;

class DiscussionFields
{
    public function __construct(
        private Sources $sources,
        private MediaRepository $media,
        private TranslatorInterface $translator
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Relationship\ToOne::make('marqueeMedia')
                ->type('marquee-media')
                ->includable(),

            Schema\Integer::make('marqueeSeason')
                ->nullable()
                ->get(fn (Discussion $d) => $d->marquee_season),

            Schema\Integer::make('marqueeEpisode')
                ->nullable()
                ->get(fn (Discussion $d) => $d->marquee_episode),

            // The linked season's episodes, on the discussion page only, so the
            // hero needs no second request. One query, and only when linked.
            Schema\Arr::make('marqueeSeasonData')
                ->nullable()
                ->visible(fn (Discussion $d, Context $context) => $d->marquee_season && $context->showing(DiscussionResource::class))
                ->get(fn (Discussion $d) => Season::query()
                    ->where('media_id', $d->marquee_media_id)
                    ->where('number', $d->marquee_season)
                    ->first()?->data),

            // Write-only: { kind, id, season?, episode? } or null to unlink.
            Schema\Attribute::make('marquee')
                ->hidden()
                ->writable(fn (Discussion $d, Context $context) => $context->creating() || $context->getActor()->can('rename', $d))
                ->set(fn (Discussion $d, mixed $value) => $this->link($d, $value)),
        ];
    }

    private function link(Discussion $discussion, mixed $value): void
    {
        if ($value === null) {
            $discussion->marquee_media_id = null;
            $discussion->marquee_season = null;
            $discussion->marquee_episode = null;

            return;
        }

        $kind = is_array($value) ? (string) ($value['kind'] ?? '') : '';
        $id = is_array($value) ? (string) ($value['id'] ?? '') : '';
        $season = isset($value['season']) ? (int) $value['season'] : null;
        $episode = isset($value['episode']) ? (int) $value['episode'] : null;

        if (! $this->sources->get($kind) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            $this->fail('invalid');
        }

        try {
            $media = $this->media->resolve($kind, $id);
        } catch (SourceException) {
            $this->fail('unavailable');
        }

        if ($season !== null && ! in_array($season, $media->seasonNumbers(), true)) {
            $this->fail('invalid');
        }

        if ($episode !== null && ($season === null || $episode < 1 || $episode > $media->episodeCount($season))) {
            $this->fail('invalid');
        }

        // Have the season stored before anyone opens the discussion. The composer
        // has usually fetched it already while the episode was picked.
        if ($season !== null) {
            try {
                $this->media->season($media, $season);
            } catch (SourceException) {
                // The page loads it later instead.
            }
        }

        $discussion->marquee_media_id = $media->id;
        $discussion->marquee_season = $season;
        $discussion->marquee_episode = $episode;
        $discussion->setRelation('marqueeMedia', $media);
    }

    private function fail(string $reason): never
    {
        throw new ValidationException(['marquee' => $this->translator->trans("ernestdefoe-marquee.api.link_$reason")]);
    }
}

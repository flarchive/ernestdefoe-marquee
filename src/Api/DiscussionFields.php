<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\Backdrops;
use Ernestdefoe\Marquee\Media;
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
use Flarum\User\User;

class DiscussionFields
{
    public function __construct(
        private Sources $sources,
        private MediaRepository $media,
        private TranslatorInterface $translator,
        private Backdrops $backdrops
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

            // The linked season's episodes, on the discussion page (and after an
            // edit to it) only, so the hero needs no second request.
            Schema\Arr::make('marqueeSeasonData')
                ->nullable()
                ->visible(fn (Discussion $d, Context $context) => $d->marquee_season && ($context->showing(DiscussionResource::class) || $context->updating(DiscussionResource::class)))
                ->get(fn (Discussion $d) => Season::query()
                    ->where('media_id', $d->marquee_media_id)
                    ->where('number', $d->marquee_season)
                    ->first()?->data),

            // Related titles (the rest of a series), on the discussion page only.
            Schema\Relationship\ToMany::make('marqueeRelatedMedia')
                ->type('marquee-media')
                ->includable()
                ->withLinkage(fn (Context $context) => $context->showing(DiscussionResource::class) || $context->updating(DiscussionResource::class))
                ->get(fn (Discussion $d) => self::related($d)),

            Schema\Boolean::make('canMarqueeLink')
                ->get(fn (Discussion $d, Context $context) => self::canLink($context->getActor(), $d)),

            // Write-only: { kind, id, season?, episode? } or null to unlink.
            Schema\Attribute::make('marquee')
                ->hidden()
                ->writable(fn (Discussion $d, Context $context) => $context->creating() || self::canLink($context->getActor(), $d))
                ->set(fn (Discussion $d, mixed $value) => $this->link($d, $value)),

            // Write-only: [{ kind, id }, …] in card order, or [] for none. After
            // `marquee`, so a change of linked title in the same save comes first.
            Schema\Attribute::make('marqueeRelated')
                ->hidden()
                ->writable(fn (Discussion $d, Context $context) => ! $context->creating() && self::canLink($context->getActor(), $d))
                ->set(fn (Discussion $d, mixed $value) => $this->relate($d, $value)),

            // Each title's own background: { titleId: "none" or an uploaded
            // image's address }; a title not in it has the source's. Uploading
            // goes through BackdropController. Written as changes after
            // `marqueeRelated`: { titleId: null (the source's) or "none" }.
            Schema\Attribute::make('marqueeBackdrops')
                ->nullable()
                ->visible(fn (Discussion $d, Context $context) => $context->showing(DiscussionResource::class) || $context->updating(DiscussionResource::class))
                ->get(fn (Discussion $d) => $this->backdrops->values($d->marquee_backdrops))
                ->writable(fn (Discussion $d, Context $context) => ! $context->creating() && self::canLink($context->getActor(), $d))
                ->set(fn (Discussion $d, mixed $value) => $this->setBackdrops($d, $value)),
        ];
    }

    /**
     * Changing a posted discussion's linked title: "Change the linked title of
     * any discussion" (discussion.marqueeLink, tag-scopable), or the author who
     * may rename their own discussion and has "...of their own discussions".
     * Neither follows from the rename permission any more.
     */
    public static function canLink(User $actor, Discussion $discussion): bool
    {
        if ($actor->can('marqueeLink', $discussion)) {
            return true;
        }

        return (int) $discussion->user_id === (int) $actor->id
            && $actor->hasPermission('ernestdefoe-marquee.linkOwn')
            && $actor->can('rename', $discussion);
    }

    private function link(Discussion $discussion, mixed $value): void
    {
        $this->pruneAfterSave($discussion);

        if ($value === null) {
            $discussion->marquee_media_id = null;
            $discussion->marquee_season = null;
            $discussion->marquee_episode = null;
            $discussion->marquee_related = null;
            $this->keepBackdrops($discussion);

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
        $discussion->marquee_related = array_values(array_diff((array) ($discussion->marquee_related ?? []), [$media->id])) ?: null;
        $discussion->marquee_season = $season;
        $discussion->marquee_episode = $episode;
        $discussion->setRelation('marqueeMedia', $media);
        $this->keepBackdrops($discussion);
    }

    /** At most this many related titles: a row of chips, not a catalogue. */
    private const RELATED = 12;

    /** @return list<Media> the related titles, in the card's order */
    private static function related(Discussion $discussion): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($discussion->marquee_related ?? []))));
        if (! $ids) {
            return [];
        }

        $media = Media::query()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => $media[$id] ?? null, $ids)));
    }

    private function relate(Discussion $discussion, mixed $value): void
    {
        $this->pruneAfterSave($discussion);

        if ($value === [] || $value === null) {
            $discussion->marquee_related = null;
            $this->keepBackdrops($discussion);

            return;
        }

        if (! $discussion->marquee_media_id || ! is_array($value) || ! array_is_list($value) || count($value) > self::RELATED) {
            $this->fail('invalid');
        }

        $ids = [];
        foreach ($value as $item) {
            $kind = is_array($item) ? (string) ($item['kind'] ?? '') : '';
            $id = is_array($item) ? (string) ($item['id'] ?? '') : '';

            if (! $this->sources->get($kind) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $this->fail('invalid');
            }

            try {
                $ids[] = $this->media->resolve($kind, $id, true)->id;
            } catch (SourceException) {
                $this->fail('unavailable');
            }
        }

        // Not the linked title itself, and each title once.
        $ids = array_values(array_unique(array_diff($ids, [(int) $discussion->marquee_media_id])));
        $discussion->marquee_related = $ids ?: null;
        $this->keepBackdrops($discussion);
    }

    /** @return list<int> the ids of the titles on the card: the linked one, then its related titles */
    public static function titleIds(Discussion $discussion): array
    {
        if (! $discussion->marquee_media_id) {
            return [];
        }

        return [(int) $discussion->marquee_media_id, ...array_map('intval', (array) ($discussion->marquee_related ?? []))];
    }

    /** Backgrounds of titles no longer on the card go, files and all. */
    private function keepBackdrops(Discussion $discussion): void
    {
        $map = (array) ($discussion->marquee_backdrops ?? []);
        $kept = array_intersect_key($map, array_flip(self::titleIds($discussion)));

        if (count($kept) !== count($map)) {
            $discussion->marquee_backdrops = $kept ?: null;
            $discussion->afterSave(fn () => $this->backdrops->deleteAll(array_diff_key($map, $kept)));
        }
    }

    /** Back to the source's (null) or to none ("none"), for titles on the card. */
    private function setBackdrops(Discussion $discussion, mixed $value): void
    {
        $ids = self::titleIds($discussion);
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            $this->fail('invalid');
        }

        $map = (array) ($discussion->marquee_backdrops ?? []);
        $gone = [];
        foreach ($value as $id => $choice) {
            if (! in_array((int) $id, $ids, true) || ($choice !== null && $choice !== Backdrops::NONE)) {
                $this->fail('invalid');
            }

            $gone[] = $map[(int) $id] ?? null;
            if ($choice === null) {
                unset($map[(int) $id]);
            } else {
                $map[(int) $id] = $choice;
            }
        }

        $discussion->marquee_backdrops = $map ?: null;
        $discussion->afterSave(function () use ($gone) {
            foreach ($gone as $path) {
                $this->backdrops->delete($path);
            }
        });
    }

    /** The titles this discussion linked to before the save go once nothing else uses them. */
    private function pruneAfterSave(Discussion $discussion): void
    {
        if (! $discussion->exists) {
            return;
        }

        $before = [$discussion->getOriginal('marquee_media_id'), ...(array) ($discussion->getOriginal('marquee_related') ?? [])];
        $discussion->afterSave(fn () => $this->media->prune($before));
    }

    private function fail(string $reason): never
    {
        throw new ValidationException(['marquee' => $this->translator->trans("ernestdefoe-marquee.api.link_$reason")]);
    }
}

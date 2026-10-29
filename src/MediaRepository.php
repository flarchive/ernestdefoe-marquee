<?php

namespace Ernestdefoe\Marquee;

use Carbon\Carbon;
use Ernestdefoe\Marquee\Source\Outage;
use Ernestdefoe\Marquee\Source\SeasonSource;
use Ernestdefoe\Marquee\Source\SourceException;
use Ernestdefoe\Marquee\Source\Sources;
use Ernestdefoe\Marquee\Translation\Translator;
use Ernestdefoe\Marquee\Translation\WikipediaOverview;

/**
 * Titles and seasons are fetched once and kept. A stored copy is refreshed only
 * when someone links to it or opens it again after it has gone stale; viewing a
 * discussion never reaches the source.
 *
 * On a non-English forum a game or book also gets its description in the data
 * language (Wikipedia, then DeepL). That takes longer than the source itself,
 * so the composer's quick look-up stores the source's data and leaves the
 * description until after its response; posting finishes whatever is left
 * before the discussion is shown. A game Wikipedia has no article on in that
 * language is looked at again daily (marquee:describe), without the source.
 */
class MediaRepository
{
    private const MEDIA_TTL_DAYS = 7;
    private const SEASON_TTL_DAYS = 3;
    private const DESCRIBE_AGAIN_HOURS = 24;

    public function __construct(
        private Sources $sources,
        private Outage $outage,
        private WikipediaOverview $wikipedia,
        private Translator $translator,
        private Later $later
    ) {
    }

    /**
     * @param bool $quick answer as soon as the source has, and look for the
     *                    description in the data language after the response
     * @throws SourceException
     */
    public function resolve(string $kind, string $externalId, bool $quick = false): Media
    {
        $media = Media::query()->where('kind', $kind)->where('external_id', $externalId)->first();
        $describes = $this->describes($kind);

        if ($media && $media->fetched_at->gt(Carbon::now()->subDays(self::MEDIA_TTL_DAYS))) {
            if ($describes && $media->describe_at?->lte(Carbon::now())) {
                $quick ? $this->later->run(fn () => $this->describe($media)) : $this->describe($media);
            }

            return $media;
        }

        // The stale copy answers the composer; the refresh waits for the response to go.
        if ($quick && $media && $describes) {
            $this->later->run(function () use ($kind, $externalId) {
                $this->resolve($kind, $externalId);
            });

            return $media;
        }

        $source = $this->sources->get($kind) ?? throw new SourceException("No source for $kind");

        try {
            $data = $this->outage->guard($kind, fn () => $source->details($externalId));
        } catch (SourceException $e) {
            // A stale copy beats no copy while the source is down.
            if ($media) {
                return $media;
            }
            throw $e;
        }

        $pending = $quick && $describes;
        if ($pending) {
            $data['pending'] = true;
        } else {
            $data = $this->describeData($kind, $data, $media?->data);
        }

        $media ??= new Media();
        $media->forceFill([
            'kind' => $kind,
            'external_id' => $externalId,
            'title' => mb_substr($data['title'], 0, 255),
            'year' => $data['year'],
            'poster' => $data['poster'],
            'data' => $data,
            'fetched_at' => Carbon::now(),
            'describe_at' => $pending ? Carbon::now() : $this->describeAt($kind, $data),
        ])->save();

        if ($pending) {
            $this->later->run(fn () => $this->describe($media));
        }

        return $media;
    }

    /**
     * Whether titles of this kind get a description in the data language here
     * (not on an English forum, nor for movies, shows and albums).
     */
    public function describes(string $kind): bool
    {
        return $this->wikipedia->applies($kind) || $this->translator->applies($kind);
    }

    /**
     * Find the description in the data language for a stored title, without
     * asking the source again: after a quick look-up, and daily for a game
     * whose article does not exist yet.
     */
    public function describe(Media $media): void
    {
        $data = $media->data;

        if (! empty($data['pending']) || $this->wikipedia->missing($media->kind, $data)) {
            unset($data['pending']);
            $data = $this->describeData($media->kind, $data, $data);
        }

        $media->forceFill(['data' => $data, 'describe_at' => $this->describeAt($media->kind, $data)])->save();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $previous
     * @return array<string, mixed>
     */
    private function describeData(string $kind, array $data, ?array $previous): array
    {
        // Outside the outage guard: Wikipedia or DeepL failing says nothing about the source.
        // Wikipedia's own words in the data language first; DeepL translates what is left.
        $data = $this->wikipedia->apply($kind, $data, $previous);

        return $this->translator->apply($kind, $data, $previous);
    }

    /** @param array<string, mixed> $data */
    private function describeAt(string $kind, array $data): ?Carbon
    {
        return $this->wikipedia->missing($kind, $data) ? Carbon::now()->addHours(self::DESCRIBE_AGAIN_HOURS) : null;
    }

    /** @throws SourceException */
    public function season(Media $media, int $number): Season
    {
        $season = $media->seasons()->where('number', $number)->first();

        if ($season && $season->fetched_at->gt(Carbon::now()->subDays(self::SEASON_TTL_DAYS))) {
            return $season;
        }

        $source = $this->sources->get($media->kind);
        if (! $source instanceof SeasonSource) {
            throw new SourceException("{$media->kind} has no seasons");
        }

        try {
            $data = $this->outage->guard($media->kind, fn () => $source->season($media->external_id, $number));
        } catch (SourceException $e) {
            if ($season) {
                return $season;
            }
            throw $e;
        }

        $season ??= new Season();
        $season->forceFill(['media_id' => $media->id, 'number' => $number, 'data' => $data, 'fetched_at' => Carbon::now()])->save();

        return $season;
    }
}

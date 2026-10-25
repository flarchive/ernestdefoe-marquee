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
 */
class MediaRepository
{
    private const MEDIA_TTL_DAYS = 7;
    private const SEASON_TTL_DAYS = 3;

    public function __construct(private Sources $sources, private Outage $outage, private WikipediaOverview $wikipedia, private Translator $translator)
    {
    }

    /** @throws SourceException */
    public function resolve(string $kind, string $externalId): Media
    {
        $media = Media::query()->where('kind', $kind)->where('external_id', $externalId)->first();

        if ($media && $media->fetched_at->gt(Carbon::now()->subDays(self::MEDIA_TTL_DAYS))) {
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

        // Outside the outage guard: Wikipedia or DeepL failing says nothing about the source.
        // Wikipedia's own words in the data language first; DeepL translates what is left.
        $data = $this->wikipedia->apply($kind, $data, $media?->data);
        $data = $this->translator->apply($kind, $data, $media?->data);

        $media ??= new Media();
        $media->forceFill([
            'kind' => $kind,
            'external_id' => $externalId,
            'title' => mb_substr($data['title'], 0, 255),
            'year' => $data['year'],
            'poster' => $data['poster'],
            'data' => $data,
            'fetched_at' => Carbon::now(),
        ])->save();

        return $media;
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

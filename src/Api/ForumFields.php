<?php

namespace Ernestdefoe\Marquee\Api;

use Ernestdefoe\Marquee\Settings;
use Ernestdefoe\Marquee\Source\Sources;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

class ForumFields
{
    public function __construct(private SettingsRepositoryInterface $settings, private Sources $sources)
    {
    }

    public function __invoke(): array
    {
        return [
            // Off when another extension already draws list thumbnails.
            Schema\Boolean::make('marqueeListPosters')->get(fn () => (bool) $this->settings->get(Settings::LIST_POSTERS)),

            // Display switches only; nothing secret.
            Schema\Boolean::make('marqueeScores')->get(fn () => (bool) $this->settings->get(Settings::SHOW_SCORES)),
            Schema\Boolean::make('marqueeSourceLink')->get(fn () => (bool) $this->settings->get(Settings::SHOW_SOURCE_LINK)),
            Schema\Boolean::make('marqueeTitleYear')->get(fn () => (bool) $this->settings->get(Settings::TITLE_YEAR)),
            Schema\Str::make('marqueeListMeta')->get(function () {
                $value = (string) $this->settings->get(Settings::LIST_META);

                return in_array($value, Settings::LIST_META_CHOICES, true) ? $value : 'kind_year';
            }),

            // Tag id => kind, for tags whose source is ready. The composer reads
            // this to decide whether the title field searches.
            Schema\Arr::make('marqueeTags')->get(function () {
                $kinds = $this->sources->kinds();
                $map = json_decode($this->settings->get(Settings::TAGS) ?: '{}', true) ?: [];

                return (object) array_filter($map, fn ($kind) => in_array($kind, $kinds, true));
            }),
        ];
    }
}

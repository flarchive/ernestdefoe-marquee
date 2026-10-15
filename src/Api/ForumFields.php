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

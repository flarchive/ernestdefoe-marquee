<?php

namespace Ernestdefoe\Marquee\Source;

use Ernestdefoe\Marquee\Settings;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The language titles and synopses are fetched in: the admin's setting, or,
 * when that is blank, the forum's default language (fr → fr, pt_BR → pt-BR).
 */
class DataLanguage
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function get(): string
    {
        $language = trim((string) $this->settings->get(Settings::LANGUAGE));
        if ($language === '') {
            $language = (string) ($this->settings->get('default_locale') ?: 'en');
        }

        $language = str_replace('_', '-', $language);

        return preg_match('/^[a-z]{2}(-[A-Za-z]{2,4})?$/', $language) ? $language : 'en-US';
    }
}

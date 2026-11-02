<?php

namespace Ernestdefoe\Marquee\Translation;

use Ernestdefoe\Marquee\Settings;
use Ernestdefoe\Marquee\Source\DataLanguage;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Translates the English text of games (IGDB) and books (Open Library) into the
 * data language with DeepL, when the admin has given a key. Movies and TV come
 * translated from TMDB already; album data is the artists' own.
 *
 * A title's translation is stored with it. When the title refreshes and its
 * English text has not changed, the stored translation is reused, so DeepL's
 * monthly allowance is spent once per title, not once a week.
 *
 * Any failure (bad key, allowance used up, a language DeepL lacks) leaves the
 * text in English: translation never stops a title from being linked.
 */
class Translator
{
    /**
     * The fields of each kind that are English text. A game's genres and modes
     * are not here: IGDB has a short fixed list of them, which the forum's
     * language files translate (forum.genre.*, forum.mode.*).
     */
    private const FIELDS = [
        'game' => ['overview'],
        'book' => ['overview', 'subtitle', 'subjects'],
    ];

    public function __construct(
        private DeepL $deepl,
        private DataLanguage $language,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log
    ) {
    }

    public function applies(string $kind): bool
    {
        return isset(self::FIELDS[$kind])
            && trim((string) $this->settings->get(Settings::DEEPL_KEY)) !== ''
            && DeepL::target($this->language->get()) !== null;
    }

    /**
     * @param array<string, mixed> $data fresh details from the source, in English
     * @param array<string, mixed>|null $previous what was stored for this title before
     * @return array<string, mixed>
     */
    public function apply(string $kind, array $data, ?array $previous): array
    {
        $key = trim((string) $this->settings->get(Settings::DEEPL_KEY));
        $target = DeepL::target($this->language->get());
        $fields = self::FIELDS[$kind] ?? null;

        // Already in the data language, from Wikipedia.
        if ($fields && isset($data['wikipedia'])) {
            $fields = array_values(array_diff($fields, ['overview']));
        }

        if ($key === '' || $target === null || $fields === null) {
            return $data;
        }

        // Every string to translate, in order, and where each one goes back.
        $texts = [];
        $slots = [];
        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            foreach (is_array($value) ? $value : [$value] as $i => $text) {
                if (is_string($text) && trim($text) !== '') {
                    $texts[] = $text;
                    $slots[] = [$field, is_array($value) ? $i : null];
                }
            }
        }

        if (! $texts) {
            return $data;
        }

        $hash = sha1($target."\n".json_encode($texts));
        $translated = null;

        // Same English as last time: the stored copy already holds its translation, slot for slot.
        if (($previous['translation']['hash'] ?? null) === $hash) {
            $translated = [];
            foreach ($slots as [$field, $index]) {
                $old = $index === null ? ($previous[$field] ?? null) : ($previous[$field][$index] ?? null);
                if (! is_string($old)) {
                    $translated = null;
                    break;
                }
                $translated[] = $old;
            }
        }

        if ($translated === null) {
            try {
                $translated = $this->deepl->translate($key, $texts, $target);
            } catch (RuntimeException $e) {
                $this->log->info('[marquee] DeepL translation skipped: '.$e->getMessage());

                return $data;
            }
        }

        foreach ($slots as $n => [$field, $index]) {
            if ($index === null) {
                $data[$field] = $translated[$n];
            } else {
                $data[$field][$index] = $translated[$n];
            }
        }

        $data['translation'] = ['by' => 'deepl', 'lang' => $target, 'hash' => $hash];

        return $data;
    }
}

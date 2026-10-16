<?php

namespace Ernestdefoe\Marquee\Translation;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * DeepL's translate API. Free-plan keys end in ":fx" and use api-free.deepl.com;
 * paid keys use api.deepl.com. One request carries every string for a title.
 */
class DeepL
{
    private ?Client $http = null;

    /**
     * @param list<string> $texts
     * @return list<string>
     * @throws RuntimeException when DeepL refuses (bad key, quota used up, unsupported language) or cannot be reached
     */
    public function translate(string $key, array $texts, string $target): array
    {
        $this->http ??= new Client(['timeout' => 8, 'connect_timeout' => 3]);
        $base = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';

        try {
            $response = $this->http->post($base.'/v2/translate', [
                'headers' => ['Authorization' => 'DeepL-Auth-Key '.$key],
                'json' => ['text' => $texts, 'source_lang' => 'EN', 'target_lang' => $target, 'preserve_formatting' => true],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('DeepL request failed: '.$e->getCode(), 0, $e);
        }

        $out = array_column(json_decode((string) $response->getBody(), true)['translations'] ?? [], 'text');
        if (count($out) !== count($texts)) {
            throw new RuntimeException('DeepL returned '.count($out).' of '.count($texts).' strings');
        }

        return array_map('strval', $out);
    }

    /** DeepL's target code for a data language (fr-FR → FR, pt-BR → PT-BR), or null for English. */
    public static function target(string $language): ?string
    {
        $language = strtolower($language);
        $base = substr($language, 0, 2);

        return match (true) {
            $base === 'en' => null,
            $language === 'pt-br' => 'PT-BR',
            $base === 'pt' => 'PT-PT',
            in_array($language, ['zh-tw', 'zh-hk', 'zh-hant'], true) => 'ZH-HANT',
            $base === 'zh' => 'ZH-HANS',
            $base === 'no', $base === 'nb' => 'NB',
            default => strtoupper($base),
        };
    }
}

<?php

namespace Ernestdefoe\Marquee\Formatter;

use s9e\TextFormatter\Configurator;

/**
 * Season and episode references in posts: S02E04, S2E4, "Season 2 Episode 4",
 * S02 and "Season 2". Each becomes a span carrying the numbers; the forum turns
 * it into a chip only inside a discussion linked to a TV show, so elsewhere the
 * text reads exactly as typed. Which show it means is the discussion's link, so
 * changing the link updates every reference.
 */
class EpisodeReferences
{
    // Word boundaries, not lookbehind: the formatter also compiles these to
    // JavaScript for the live preview, and its converter has no lookbehind.
    private const EDGE_BEFORE = '\\b';
    private const EDGE_AFTER = '\\b';

    private const TEMPLATE = '<span class="MarqueeRef" data-season="{@season}" data-episode="{@episode}"><xsl:value-of select="@ref"/></span>';

    public function __invoke(Configurator $config): void
    {
        $patterns = [
            'MQEP' => '[Ss](?<season>\d{1,2}) ?[Ee](?<episode>\d{1,3})',
            'MQEPLONG' => '[Ss]eason (?<season>\d{1,2}),? [Ee]pisode (?<episode>\d{1,3})',
            'MQSEASON' => '[Ss](?<season>\d{1,2})',
            // Not when "Episode" follows: that is MQEPLONG's.
            'MQSEASONLONG' => '[Ss]eason (?<season>\d{1,2})(?!,? [Ee]pisode)',
        ];

        foreach ($patterns as $tag => $pattern) {
            $config->Preg->replace(
                '/'.self::EDGE_BEFORE.'(?<ref>'.$pattern.')'.self::EDGE_AFTER.'/u',
                self::TEMPLATE,
                $tag
            );
        }
    }
}

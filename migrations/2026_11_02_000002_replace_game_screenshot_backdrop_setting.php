<?php

use Illuminate\Database\Schema\Builder;

/**
 * The on/off "screenshot as background" switch became a choice of three. A
 * forum that had turned screenshots off keeps artwork only.
 */
return [
    'up' => function (Builder $schema) {
        $settings = $schema->getConnection()->table('settings');
        $old = $settings->where('key', 'ernestdefoe-marquee.game_screenshot_backdrop')->value('value');

        if ($old !== null && ! (bool) $old) {
            $schema->getConnection()->table('settings')->updateOrInsert(['key' => 'ernestdefoe-marquee.game_backdrop'], ['value' => 'artwork']);
        }

        $schema->getConnection()->table('settings')->where('key', 'ernestdefoe-marquee.game_screenshot_backdrop')->delete();
    },
    'down' => function (Builder $schema) {
    },
];

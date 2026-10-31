<?php

use Illuminate\Database\Schema\Builder;

/**
 * Nothing to add. Purging the extension runs this first, and takes Marquee's
 * settings (source keys included) with it.
 */
return [
    'up' => function (Builder $schema) {
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'like', 'ernestdefoe-marquee.%')->delete();
    },
];

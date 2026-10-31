<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * When to look again for a title's description in the data language: soon
 * after a quick look-up stored the source's own text, then daily for a game
 * whose Wikipedia article in that language does not exist yet. Null = nothing
 * to look for. Games stored before this are looked at once more (a French
 * article may have appeared since).
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('marquee_media', function (Blueprint $table) {
            $table->dateTime('describe_at')->nullable()->index();
        });

        $schema->getConnection()->table('marquee_media')->where('kind', 'game')->update(['describe_at' => Carbon::now()]);
    },
    'down' => function (Builder $schema) {
        $schema->table('marquee_media', function (Blueprint $table) {
            $table->dropIndex(['describe_at']);
            $table->dropColumn('describe_at');
        });
    },
];

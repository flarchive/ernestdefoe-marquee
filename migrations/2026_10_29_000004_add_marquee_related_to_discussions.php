<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Titles related to the linked one (the other games of a series), picked by
 * hand: stored titles' ids, in the order the card shows them.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->text('marquee_related')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropColumn('marquee_related');
        });
    },
];

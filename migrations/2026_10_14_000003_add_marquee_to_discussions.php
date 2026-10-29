<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * What a discussion is about: a movie, show or game, and for a show optionally
 * one season or one episode of it.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->unsignedInteger('marquee_media_id')->nullable();
            $table->unsignedSmallInteger('marquee_season')->nullable();
            $table->unsignedSmallInteger('marquee_episode')->nullable();

            $table->foreign('marquee_media_id')->references('id')->on('marquee_media')->nullOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->dropForeign(['marquee_media_id']);
            $table->dropColumn(['marquee_media_id', 'marquee_season', 'marquee_episode']);
        });
    },
];

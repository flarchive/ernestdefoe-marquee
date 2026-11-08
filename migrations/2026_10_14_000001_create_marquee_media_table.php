<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per movie, show or game a discussion has linked to. `data` holds the
 * normalised details fetched once from the source, so viewing a discussion
 * never calls TMDB or IGDB.
 */
return Migration::createTable('marquee_media', function (Blueprint $table) {
    $table->increments('id');
    $table->string('kind', 8);                  // movie | tv | game
    $table->string('external_id', 64);
    $table->string('title', 255);
    $table->unsignedSmallInteger('year')->nullable();
    $table->string('poster', 255)->nullable();
    $table->json('data');
    $table->dateTime('fetched_at');

    $table->unique(['kind', 'external_id']);
});

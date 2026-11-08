<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A TV season and all its episodes (crew and guest stars included), fetched in
 * one call the first time anyone opens that season.
 */
return Migration::createTable('marquee_seasons', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('media_id');
    $table->unsignedSmallInteger('number');
    $table->json('data');
    $table->dateTime('fetched_at');

    $table->unique(['media_id', 'number']);
    $table->foreign('media_id')->references('id')->on('marquee_media')->cascadeOnDelete();
});

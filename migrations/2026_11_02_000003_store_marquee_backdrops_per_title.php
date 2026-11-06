<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Each title on a card (the linked one and its related titles) has its own
 * background: `marquee_backdrops` maps a title's id to an uploaded image's
 * path or "none". The single `marquee_backdrop` belonged to the linked title.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->text('marquee_backdrops')->nullable();
        });

        $db = $schema->getConnection();
        $db->table('discussions')->whereNotNull('marquee_backdrop')->whereNotNull('marquee_media_id')
            ->orderBy('id')->select(['id', 'marquee_media_id', 'marquee_backdrop'])
            ->each(fn ($row) => $db->table('discussions')->where('id', $row->id)
                ->update(['marquee_backdrops' => json_encode([(string) $row->marquee_media_id => $row->marquee_backdrop])]));

        $schema->table('discussions', function (Blueprint $table) {
            $table->dropColumn('marquee_backdrop');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('discussions', function (Blueprint $table) {
            $table->string('marquee_backdrop', 100)->nullable();
        });

        $db = $schema->getConnection();
        $db->table('discussions')->whereNotNull('marquee_backdrops')
            ->orderBy('id')->select(['id', 'marquee_media_id', 'marquee_backdrops'])
            ->each(fn ($row) => $db->table('discussions')->where('id', $row->id)
                ->update(['marquee_backdrop' => json_decode($row->marquee_backdrops, true)[$row->marquee_media_id] ?? null]));

        $schema->table('discussions', function (Blueprint $table) {
            $table->dropColumn('marquee_backdrops');
        });
    },
];

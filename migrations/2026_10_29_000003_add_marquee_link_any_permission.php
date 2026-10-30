<?php

use Illuminate\Database\Schema\Builder;

/**
 * Changing the linked title of any discussion was part of renaming it. It is a
 * permission of its own now (tag-scopable, like core's discussion permissions),
 * given to every group that may rename today, globally or in a tag, so nothing
 * changes until an admin adjusts it.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $rows = $db->table('group_permission')
            ->where('permission', 'discussion.rename')
            ->orWhere('permission', 'like', 'tag%.discussion.rename')
            ->get(['group_id', 'permission']);

        $db->table('group_permission')->insertOrIgnore($rows->map(fn ($row) => [
            'group_id' => $row->group_id,
            'permission' => substr($row->permission, 0, -strlen('rename')).'marqueeLink',
        ])->all());
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('group_permission')
            ->where('permission', 'discussion.marqueeLink')
            ->orWhere('permission', 'like', 'tag%.discussion.marqueeLink')
            ->delete();
    },
];

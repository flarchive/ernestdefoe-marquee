<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Changing the linked title of one's own discussion was part of renaming it.
 * It is a permission of its own now, given to members so nothing changes until
 * an admin takes it away.
 */
return Migration::addPermissions([
    'ernestdefoe-marquee.linkOwn' => Group::MEMBER_ID,
]);

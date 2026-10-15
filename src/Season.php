<?php

namespace Ernestdefoe\Marquee;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $media_id
 * @property int $number
 * @property array $data
 * @property \Carbon\Carbon $fetched_at
 */
class Season extends AbstractModel
{
    protected $table = 'marquee_seasons';

    public $timestamps = false;

    protected $casts = ['data' => 'array', 'fetched_at' => 'datetime', 'number' => 'integer'];
}

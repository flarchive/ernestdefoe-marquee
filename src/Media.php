<?php

namespace Ernestdefoe\Marquee;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $kind
 * @property string $external_id
 * @property string $title
 * @property int|null $year
 * @property string|null $poster
 * @property array $data
 * @property \Carbon\Carbon $fetched_at
 * @property \Carbon\Carbon|null $describe_at
 */
class Media extends AbstractModel
{
    protected $table = 'marquee_media';

    public $timestamps = false;

    protected $casts = ['data' => 'array', 'fetched_at' => 'datetime', 'describe_at' => 'datetime', 'year' => 'integer'];

    /** @return HasMany<Season, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class, 'media_id');
    }

    /** Season numbers the source lists for this show (specials excluded). */
    public function seasonNumbers(): array
    {
        return array_column($this->data['seasons'] ?? [], 'number');
    }

    public function episodeCount(int $season): int
    {
        foreach ($this->data['seasons'] ?? [] as $s) {
            if ($s['number'] === $season) {
                return (int) $s['episodes'];
            }
        }

        return 0;
    }
}

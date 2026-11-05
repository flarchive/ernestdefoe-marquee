<?php

namespace Ernestdefoe\Marquee;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * A card's own backgrounds, in place of the source's artwork: one for each
 * title on it (the linked one and its related titles), uploaded by whoever may
 * change its linked title and stored with the forum's other assets.
 * `discussions.marquee_backdrops` maps a title's id to an image's path, or to
 * "none" for no image at all; a title not in it has the source's own.
 */
class Backdrops
{
    public const NONE = 'none';

    /** Wide enough for a full-width card on a large screen, and no wider. */
    private const WIDTH = 1920;

    private Cloud $disk;

    public function __construct(Factory $filesystems, private ImageManager $images)
    {
        $this->disk = $filesystems->disk('flarum-assets');
    }

    /** Re-encoded, so nothing of the upload but its pixels is kept. @return string the stored path */
    public function store(int $discussionId, string $file): string
    {
        $image = $this->images->read($file)->scaleDown(width: self::WIDTH);
        $path = 'marquee-backdrops/'.$discussionId.'-'.Str::lower(Str::random(10)).'.webp';
        $this->disk->put($path, (string) $image->toWebp(82));

        return $path;
    }

    public function delete(?string $value): void
    {
        if ($value && $value !== self::NONE && str_starts_with($value, 'marquee-backdrops/')) {
            $this->disk->delete($value);
        }
    }

    /** @param array<int|string, string>|null $map */
    public function deleteAll(?array $map): void
    {
        foreach ($map ?? [] as $value) {
            $this->delete($value);
        }
    }

    /**
     * What the card is told: each title's id with "none" or its image's address.
     *
     * @param array<int|string, string>|null $map
     * @return array<string, string>|null
     */
    public function values(?array $map): ?array
    {
        $values = [];
        foreach ($map ?? [] as $id => $value) {
            $values[(string) $id] = $value === self::NONE ? self::NONE : $this->disk->url($value);
        }

        return $values ?: null;
    }

    /** A setting's colour as #rrggbb, or null when it is empty or not one. */
    public static function color(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^#([0-9a-f]{3}){1,2}$/i', $value) ? strtolower($value) : null;
    }
}

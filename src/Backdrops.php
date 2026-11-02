<?php

namespace Ernestdefoe\Marquee;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

/**
 * A discussion's own card background, in place of the source's artwork:
 * uploaded by whoever may change its linked title, stored with the forum's
 * other assets. `discussions.marquee_backdrop` holds its path, or "none" for
 * no image at all, or nothing for the source's own.
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

    /** What the card is told: null (the source's own), "none", or the image's address. */
    public function value(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value === self::NONE ? self::NONE : $this->disk->url($value);
    }

    /** A setting's colour as #rrggbb, or null when it is empty or not one. */
    public static function color(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^#([0-9a-f]{3}){1,2}$/i', $value) ? strtolower($value) : null;
    }
}

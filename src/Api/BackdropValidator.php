<?php

namespace Ernestdefoe\Marquee\Api;

use Flarum\Foundation\AbstractImageValidator;

/** Core's image checks (type, size, pixel count), with room for a large photo. */
class BackdropValidator extends AbstractImageValidator
{
    public function getMaxSize(): int
    {
        return 8192;
    }

    protected function getAllowedTypes(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }
}

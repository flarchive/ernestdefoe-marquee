<?php

namespace Ernestdefoe\Marquee\Source;

/** A source whose titles have seasons and episodes. */
interface SeasonSource extends Source
{
    /** One season with all its episodes, crew and guest stars included. */
    public function season(string $id, int $number): array;
}

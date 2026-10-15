<?php

namespace Ernestdefoe\Marquee\Source;

/**
 * Somewhere titles of one kind come from. Results and details are normalised
 * here, so the rest of the extension never sees a provider's own format.
 */
interface Source
{
    /** movie | tv | game */
    public function kind(): string;

    /** Whether the admin has given this source what it needs to work. */
    public function configured(): bool;

    /** @return list<array{id: string, title: string, year: ?int, poster: ?string, subtitle: string}> */
    public function search(string $query): array;

    /** Normalised details: must include title, year and poster. */
    public function details(string $id): array;
}

<?php

namespace Ernestdefoe\Marquee\Tests\integration;

use Ernestdefoe\Marquee\Source\IgdbGames;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * IGDB files some real games as bundles (UFO 50, Collection of SaGa), so bundles are searched;
 * shop bundles nobody rated must still come after the games. Plain ranking: no credentials needed.
 */
class IgdbRankTest extends TestCase
{
    #[Test]
    public function bundles_are_searched(): void
    {
        $this->assertStringContainsString('3', IgdbGames::GAME_TYPES);
    }

    #[Test]
    public function a_rated_bundle_that_is_the_game_comes_first(): void
    {
        // What IGDB answers for "UFO 50": the game itself is a bundle (type 3).
        $ranked = IgdbGames::rank([
            ['id' => 2, 'name' => 'UFO 50 Nonograms', 'game_type' => 0, 'total_rating_count' => 3],
            ['id' => 1, 'name' => 'UFO 50', 'game_type' => 3, 'total_rating_count' => 112],
        ], 'UFO 50');

        $this->assertSame([1, 2], array_column($ranked, 'id'));
    }

    #[Test]
    public function unrated_shop_bundles_come_after_every_game(): void
    {
        $ranked = IgdbGames::rank([
            ['id' => 10, 'name' => 'Halo 3 + Halo Wars', 'game_type' => 3],
            ['id' => 11, 'name' => 'Halo: Combat Evolved', 'game_type' => 0, 'total_rating_count' => 900],
            ['id' => 12, 'name' => 'Halo Infinite', 'game_type' => 0],
            ['id' => 13, 'name' => 'Halo: The Master Chief Collection', 'game_type' => 3, 'total_rating_count' => 400],
        ], 'Halo');

        $this->assertSame([11, 13, 12, 10], array_column($ranked, 'id'));
    }

    #[Test]
    public function rows_without_an_id_or_name_are_dropped(): void
    {
        $this->assertSame([], IgdbGames::rank([['name' => 'x'], ['id' => 1], 'junk'], 'x'));
    }
}

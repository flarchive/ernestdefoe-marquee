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
    public function editions_are_searched(): void
    {
        // "Shovel Knight: Treasure Trove" (a bundle) and "Romancing SaGa: Minstrel Song Remastered
        // International Special Edition" (a main game) are both editions of another game.
        $this->assertStringNotContainsString('version_parent', IgdbGames::WHERE);
    }

    #[Test]
    public function an_edition_comes_after_its_game(): void
    {
        // IGDB's answers for "Shovel Knight" (2026-10-26).
        $ranked = IgdbGames::rank([
            ['id' => 26848, 'name' => 'Shovel Knight: Treasure Trove', 'game_type' => 3, 'version_parent' => 7444, 'total_rating_count' => 123],
            ['id' => 23910, 'name' => 'Shovel Knight: Specter of Torment', 'game_type' => 4, 'total_rating_count' => 50],
            ['id' => 7444, 'name' => 'Shovel Knight', 'game_type' => 0, 'total_rating_count' => 549],
        ], 'Shovel Knight');
        $this->assertSame([7444, 26848, 23910], array_column($ranked, 'id'));

        // Even with more ratings than its game, or the same.
        $ranked = IgdbGames::rank([
            ['id' => 99959, 'name' => 'Mass Effect 2: Ultimate Edition', 'game_type' => 0, 'version_parent' => 74, 'total_rating_count' => 3000],
            ['id' => 43957, 'name' => "Mass Effect 2: Collector's Edition", 'game_type' => 0, 'version_parent' => 74],
            ['id' => 74, 'name' => 'Mass Effect 2', 'game_type' => 0, 'total_rating_count' => 2799],
        ], 'mass effect 2');
        $this->assertSame([74, 99959, 43957], array_column($ranked, 'id'));
    }

    #[Test]
    public function an_edition_named_exactly_comes_first(): void
    {
        // Nobody has rated it yet, and the search leaves out the colon.
        $ranked = IgdbGames::rank([
            ['id' => 203315, 'name' => 'Romancing SaGa: Minstrel Song Remastered', 'game_type' => 9, 'total_rating_count' => 3],
            ['id' => 391997, 'name' => 'Romancing SaGa: Minstrel Song Remastered International Special Edition', 'game_type' => 0, 'version_parent' => 203315],
        ], 'Romancing SaGa Minstrel Song Remastered International Special Edition');
        $this->assertSame([391997, 203315], array_column($ranked, 'id'));
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
    public function expansions_and_dlc_are_searched(): void
    {
        // Elden Ring: Shadow of the Erdtree is an expansion (2); story DLC is filed as DLC (1).
        $this->assertSame('(0,1,2,3,4,8,9,10,11)', IgdbGames::GAME_TYPES);
    }

    #[Test]
    public function an_expansion_ranks_by_its_ratings_and_unrated_dlc_comes_last(): void
    {
        // What IGDB answers for "Elden Ring", trimmed.
        $ranked = IgdbGames::rank([
            ['id' => 20, 'name' => 'Elden Ring: Tarnished Pack', 'game_type' => 1],
            ['id' => 21, 'name' => 'Elden Ring: Shadow of the Erdtree', 'game_type' => 2, 'total_rating_count' => 133],
            ['id' => 22, 'name' => 'Elden Ring', 'game_type' => 0, 'total_rating_count' => 2338],
            ['id' => 23, 'name' => 'Elden Ring Demake', 'game_type' => 0],
            ['id' => 24, 'name' => 'Elden Ring Nightreign', 'game_type' => 0, 'total_rating_count' => 110],
        ], 'Elden Ring');

        $this->assertSame([22, 21, 24, 23, 20], array_column($ranked, 'id'));
    }

    #[Test]
    public function results_say_what_kind_of_release_they_are(): void
    {
        $this->assertNull(IgdbGames::typeLabel(['game_type' => 0]));
        $this->assertSame('expansion', IgdbGames::typeLabel(['game_type' => 2, 'version_parent' => 1]));
        $this->assertSame('bundle', IgdbGames::typeLabel(['game_type' => 3]));
        $this->assertSame('edition', IgdbGames::typeLabel(['game_type' => 0, 'version_parent' => 119133]));
        $this->assertNull(IgdbGames::typeLabel(['game_type' => 99]));
    }

    #[Test]
    public function rows_without_an_id_or_name_are_dropped(): void
    {
        $this->assertSame([], IgdbGames::rank([['name' => 'x'], ['id' => 1], 'junk'], 'x'));
    }
}

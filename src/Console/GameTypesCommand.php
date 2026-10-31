<?php

namespace Ernestdefoe\Marquee\Console;

use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\Source\IgdbClient;
use Ernestdefoe\Marquee\Source\IgdbGames;
use Ernestdefoe\Marquee\Source\SourceException;
use Flarum\Console\AbstractCommand;

/**
 * Games stored before Marquee 1.15 have no version label (Expansion, Remake…)
 * for their card. One IGDB query fills in up to 500 of them; it runs daily
 * until none are left, then finds nothing to do and asks IGDB nothing.
 */
class GameTypesCommand extends AbstractCommand
{
    private const BATCH = 500;

    public function __construct(private IgdbClient $igdb)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('marquee:game-types')
            ->setDescription('Fill in the version label (Expansion, Remake…) of games linked before Marquee 1.15.');
    }

    protected function fire(): int
    {
        $missing = [];
        Media::query()->where('kind', 'game')->select(['id', 'external_id', 'data'])->lazyById(200)
            ->each(function (Media $game) use (&$missing) {
                if (! array_key_exists('type', $game->data ?? []) && ctype_digit($game->external_id)) {
                    $missing[$game->external_id] = $game;
                }

                return count($missing) < self::BATCH;
            });

        if (! $missing || ! $this->igdb->configured()) {
            $this->info('Nothing to fill in.');

            return 0;
        }

        try {
            $found = $this->igdb->query('games', 'fields game_type,version_parent; where id = ('.implode(',', array_keys($missing)).'); limit '.self::BATCH.';');
        } catch (SourceException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $types = array_column(array_map(fn ($g) => ['id' => $g['id'], 'type' => IgdbGames::typeLabel($g)], $found), 'type', 'id');

        // A game IGDB no longer lists is marked too, so it isn't asked about again.
        foreach ($missing as $id => $game) {
            $game->data = ['type' => $types[$id] ?? null] + $game->data;
            $game->save();
        }

        $this->info('Filled in '.count($missing).' games.');

        return 0;
    }
}

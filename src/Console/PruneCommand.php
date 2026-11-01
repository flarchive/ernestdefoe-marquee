<?php

namespace Ernestdefoe\Marquee\Console;

use Ernestdefoe\Marquee\MediaRepository;
use Flarum\Console\AbstractCommand;

/**
 * Deletes stored titles nothing links to: titles looked up in the composer but
 * never posted, and any a discussion let go of before Marquee 1.15.1. A title
 * a discussion lets go of now is deleted straight away.
 */
class PruneCommand extends AbstractCommand
{
    public function __construct(private MediaRepository $media)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('marquee:prune')
            ->setDescription('Delete stored titles no discussion links to any more.');
    }

    protected function fire(): int
    {
        $this->info('Deleted '.$this->media->prune().' titles.');

        return 0;
    }
}

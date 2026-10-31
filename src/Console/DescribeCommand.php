<?php

namespace Ernestdefoe\Marquee\Console;

use Carbon\Carbon;
use Ernestdefoe\Marquee\Media;
use Ernestdefoe\Marquee\MediaRepository;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Looks again, a few titles a day, for descriptions in the data language that
 * were missing: a game whose Wikipedia article in that language did not exist
 * yet when it was linked, or a quick look-up whose description never followed.
 * Never asks IGDB or TMDB. Does nothing on an English forum.
 */
class DescribeCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('marquee:describe')
            ->setDescription('Look again for missing game descriptions in the data language (Wikipedia, DeepL).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many titles to look at', '25');
    }

    protected function fire(): int
    {
        $media = resolve(MediaRepository::class);
        $count = 0;

        Media::query()
            ->whereNotNull('describe_at')
            ->where('describe_at', '<=', Carbon::now())
            ->orderBy('describe_at')
            ->limit(max(1, (int) $this->input->getOption('limit')))
            ->get()
            ->each(function (Media $title) use ($media, &$count) {
                if ($media->describes($title->kind)) {
                    $media->describe($title);
                    $count++;
                }
            });

        $this->info("Looked at $count titles.");

        return 0;
    }
}

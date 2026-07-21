<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure\Console;

use App\Ebook\Application\EbookUploadStaging;
use App\Ebook\Domain\MediaRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes staged files that were never committed — the leftovers of abandoned
 * publish wizards. Run on a schedule (e.g. hourly). Only `pending` media are
 * touched; committed (`ready`) files are never affected.
 */
#[AsCommand(name: 'ebook:prune-staged', description: 'Delete staged files from abandoned publish wizards.')]
final class PruneStagedMediaCommand extends Command
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly EbookUploadStaging $staging,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Age threshold in hours', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = new \DateTimeImmutable(sprintf('-%d hours', (int) $input->getOption('hours')));

        $count = 0;
        foreach ($this->media->stalePending($before) as $media) {
            $this->staging->discard($media);
            ++$count;
        }

        // Chunk directories from uploads abandoned before finalisation have no
        // Media row of their own, so prune them by their (time-ordered) upload id.
        $chunks = $this->staging->pruneStaleChunks($before);

        $io->success(sprintf('Pruned %d staged file(s) and %d abandoned chunk upload(s).', $count, $chunks));

        return Command::SUCCESS;
    }
}

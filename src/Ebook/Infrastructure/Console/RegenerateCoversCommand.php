<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure\Console;

use App\Ebook\Application\GenerateCoverThumbnails;
use App\Ebook\Application\GenerateCoverThumbnailsHandler;
use App\Ebook\Domain\EbookRepository;
use App\Shared\Application\Bus\CommandBus;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Regenerates COVER thumbnails. It targets covers only — it works off
 * `ebooks.cover_media_id` (and a single ebook's getCover()), so eBook content
 * files (pdf/epub, linked via ebook_files, not as a cover) are never touched.
 *
 * By default it ENQUEUES a GenerateCoverThumbnails job per cover (same handler +
 * retries as the upload flow) — run the worker to process them. With --sync it
 * does the work in-process instead (handy for a one-off or when no worker is
 * running; requires ext-grpc + the media service).
 *
 * It never touches the Media entity — thumbnails live entirely in media_thumbnails,
 * which the handler replaces wholesale, so re-running is safe and idempotent.
 */
#[AsCommand(
    name: 'ebook:regenerate-covers',
    description: 'Regenerate cover thumbnails for one ebook or all ebooks with a cover.',
)]
final class RegenerateCoversCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly GenerateCoverThumbnailsHandler $handler,
        private readonly EbookRepository $ebooks,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ebook', InputArgument::OPTIONAL, 'Ebook id (UUID) whose cover to regenerate')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Regenerate every ebook that has a cover')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Process now in-process instead of enqueueing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ebookId = $input->getArgument('ebook');
        $all = (bool) $input->getOption('all');
        $sync = (bool) $input->getOption('sync');

        if (null === $ebookId && !$all) {
            $io->error('Pass an <ebook> id or --all.');

            return Command::INVALID;
        }
        if (null !== $ebookId && $all) {
            $io->error('Pass either an <ebook> id or --all, not both.');

            return Command::INVALID;
        }

        $mediaIds = $all
            ? $this->allCoverMediaIds()
            : array_values(array_filter([$this->coverMediaIdFor((string) $ebookId, $io)]));

        if ([] === $mediaIds) {
            if ($all) {
                $io->warning('No ebooks with a cover found.');
            }

            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Regenerating cover thumbnails for %d ebook(s) [%s]',
            \count($mediaIds),
            $sync ? 'sync' : 'async',
        ));

        return $sync ? $this->runSync($mediaIds, $io) : $this->enqueue($mediaIds, $io);
    }

    /** @param list<string> $mediaIds */
    private function enqueue(array $mediaIds, SymfonyStyle $io): int
    {
        foreach ($mediaIds as $mediaId) {
            $this->commandBus->dispatch(new GenerateCoverThumbnails($mediaId));
        }
        $io->success(sprintf(
            'Enqueued %d job(s). Process them with: php bin/console messenger:consume async',
            \count($mediaIds),
        ));

        return Command::SUCCESS;
    }

    /** @param list<string> $mediaIds */
    private function runSync(array $mediaIds, SymfonyStyle $io): int
    {
        /** @var array<string, string> $failures */
        $failures = [];

        $io->progressStart(\count($mediaIds));
        foreach ($mediaIds as $mediaId) {
            try {
                ($this->handler)(new GenerateCoverThumbnails($mediaId));
            } catch (\Throwable $e) {
                $failures[$mediaId] = $e->getMessage();
            }
            $io->progressAdvance();
        }
        $io->progressFinish();

        if ([] !== $failures) {
            foreach ($failures as $mediaId => $message) {
                $io->warning(sprintf('%s: %s', $mediaId, $message));
            }
            $io->error(sprintf('%d of %d failed.', \count($failures), \count($mediaIds)));

            return Command::FAILURE;
        }

        $io->success(sprintf('Regenerated thumbnails for %d cover(s).', \count($mediaIds)));

        return Command::SUCCESS;
    }

    private function coverMediaIdFor(string $ebookId, SymfonyStyle $io): ?string
    {
        if (!Uuid::isValid($ebookId)) {
            $io->error(sprintf('Not a valid ebook id: %s', $ebookId));

            return null;
        }

        $ebook = $this->ebooks->get(Uuid::fromString($ebookId));
        if (null === $ebook) {
            $io->error(sprintf('Ebook not found: %s', $ebookId));

            return null;
        }

        $cover = $ebook->getCover();
        if (null === $cover) {
            $io->warning('This ebook has no cover — nothing to regenerate.');

            return null;
        }

        return $cover->getId()->toRfc4122();
    }

    /**
     * Cover media ids only — read straight off ebooks.cover_media_id, so eBook
     * content files (pdf/epub) are structurally excluded.
     *
     * @return list<string>
     */
    private function allCoverMediaIds(): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT cover_media_id FROM ebooks WHERE cover_media_id IS NOT NULL ORDER BY created_at',
        );
    }
}

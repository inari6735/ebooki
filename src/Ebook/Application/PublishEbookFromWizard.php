<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\Attribute;
use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookFile;
use App\Ebook\Domain\EbookFileFormat;
use App\Ebook\Domain\CategoryRepository;
use App\Ebook\Domain\EbookFileRole;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Turns the wizard's session data into a persisted {@see Ebook}: builds the
 * aggregate, commits every staged file (cover + content files) from staging to
 * its permanent home, links them as {@see EbookFile}s, and saves. Called once,
 * at step 4, for both "Zapisz szkic" (draft) and "Opublikuj" (published).
 */
final readonly class PublishEbookFromWizard
{
    public function __construct(
        private EbookRepository $ebooks,
        private MediaRepository $media,
        private CategoryRepository $categories,
        private EbookUploadStaging $staging,
        private EbookPricingFactory $pricing,
        private SluggerInterface $slugger,
    ) {
    }

    public function __invoke(PublishEbookData $data, Uuid $ownerId, bool $asDraft): Ebook
    {
        $id = Uuid::v7();

        $ebook = new Ebook(
            $id,
            $ownerId,
            (string) $data->title,
            $this->uniqueSlug((string) $data->title),
            (string) $data->author,
            $data->language ?? 'pl',
            $this->pricing->fromData($data),
        );
        $ebook->describe($data->shortDescription, $data->description);
        $ebook->setAttributes(...array_map(
            static fn (array $row): Attribute => new Attribute($row['key'], $row['value']),
            $data->details,
        ));

        if (null !== $data->category) {
            $ebook->assignCategory($this->categories->findBySlug($data->category));
        }

        $destination = 'ebooks/'.$id->toRfc4122();

        if (null !== $data->coverMediaId && null !== ($cover = $this->media->get(Uuid::fromString($data->coverMediaId)))) {
            $this->staging->commit($cover, $destination);
            $ebook->assignCover($cover);
        }

        $isPrimary = true;
        foreach ($data->files as $ref) {
            $media = $this->media->get(Uuid::fromString($ref['mediaId']));
            if (null === $media) {
                continue;
            }
            $this->staging->commit($media, $destination);
            $ebook->addFile(new EbookFile(
                Uuid::v7(),
                $ebook,
                $media,
                EbookFileFormat::from($ref['format']),
                EbookFileRole::FULL,
                $isPrimary,
            ));
            $isPrimary = false;
        }

        if (!$asDraft) {
            $ebook->publish(new \DateTimeImmutable());
        }

        $this->ebooks->save($ebook);

        return $ebook;
    }

    private function uniqueSlug(string $title): string
    {
        $base = strtolower($this->slugger->slug($title)->toString()) ?: 'ebook';
        $slug = $base;
        while ($this->ebooks->slugExists($slug)) {
            $slug = $base.'-'.substr(Uuid::v7()->toRfc4122(), 0, 8);
        }

        return substr($slug, 0, 230);
    }
}

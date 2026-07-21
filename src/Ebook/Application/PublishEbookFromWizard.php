<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\Attribute;
use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookFile;
use App\Ebook\Domain\EbookFileFormat;
use App\Ebook\Domain\EbookFileRole;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Language;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\Pricing\Pricing;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
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
        private EbookUploadStaging $staging,
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
            Language::from($data->language),
            $this->pricing($data),
        );
        $ebook->describe($data->shortDescription, $data->description);
        $ebook->setAttributes(...array_map(
            static fn (array $row): Attribute => new Attribute($row['key'], $row['value']),
            $data->details,
        ));

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

    private function pricing(PublishEbookData $data): Pricing
    {
        if ($data->isFree) {
            return Pricing::free(Currency::PLN);
        }
        if ($data->payWhatYouWant) {
            return Pricing::payWhatYouWant(
                Currency::PLN,
                null !== $data->price ? $this->money($data->price) : null,
            );
        }

        $price = $this->money($data->price ?? 0.0);
        // Only treat the promo as active when it is a genuine discount.
        $promo = (null !== $data->promoPrice && $data->promoPrice > 0 && $data->promoPrice < ($data->price ?? 0.0))
            ? $this->money($data->promoPrice)
            : null;

        return Pricing::fixed($price, $promo);
    }

    private function money(float $major): Money
    {
        return Money::of((int) round($major * 100), Currency::PLN);
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

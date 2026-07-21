<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\Attribute;
use App\Ebook\Domain\CategoryRepository;
use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;

/**
 * Applies edited detail + pricing data to an existing eBook. Reuses the exact same
 * DTO → domain mappings as publishing (pricing via {@see EbookPricingFactory},
 * attributes, category), so an edit produces the same result the wizard would.
 * Files and the cover are not touched here (managed separately).
 */
final readonly class UpdateEbook
{
    public function __construct(
        private EbookRepository $ebooks,
        private CategoryRepository $categories,
        private EbookPricingFactory $pricing,
    ) {
    }

    public function __invoke(Ebook $ebook, PublishEbookData $data): void
    {
        $ebook->updateDetails((string) $data->title, (string) $data->author, $data->language ?? 'pl');
        $ebook->describe($data->shortDescription, $data->description);
        $ebook->setAttributes(...array_map(
            static fn (array $row): Attribute => new Attribute($row['key'], $row['value']),
            $data->details,
        ));
        $ebook->assignCategory(null !== $data->category ? $this->categories->findBySlug($data->category) : null);
        $ebook->changePricing($this->pricing->fromData($data));

        $this->ebooks->save($ebook);
    }
}

<?php declare(strict_types=1);

namespace App\Ebook\Presentation\PublishEbook;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Carries the wizard's accumulated data across steps (stored in the session).
 * No persistence layer yet — publishing is a no-op for now.
 */
final class PublishEbookData
{
    // Step 1 — file (names only; binaries are not persisted yet)
    public ?string $fileName = null;
    public ?string $fileSize = null;
    public ?string $coverName = null;
    public ?string $coverDataUri = null; // base64 preview (no file store yet)

    // Step 2 — details
    #[Assert\NotBlank(groups: ['details'])]
    #[Assert\Length(max: 200, groups: ['details'])]
    public ?string $title = null;

    #[Assert\NotBlank(groups: ['details'])]
    #[Assert\Length(max: 100, groups: ['details'])]
    public ?string $author = null;

    #[Assert\NotBlank(groups: ['details'])]
    public ?string $category = null;

    #[Assert\NotBlank(groups: ['details'])]
    public string $language = 'pl';

    #[Assert\Length(max: 150, groups: ['details'])]
    public ?string $shortDescription = null;

    #[Assert\NotBlank(groups: ['details'])]
    #[Assert\Length(max: 2000, groups: ['details'])]
    public ?string $description = null;

    /** @var list<string> */
    public array $keywords = [];

    /** @var list<string> */
    public array $genres = [];

    /** @var list<array{key: string, value: string}> extra key:value rows shown as a table on the detail page */
    public array $details = [];

    // Step 3 — pricing
    #[Assert\NotBlank(groups: ['pricing'])]
    #[Assert\Positive(groups: ['pricing'])]
    public ?float $price = null;

    #[Assert\PositiveOrZero(groups: ['pricing'])]
    public ?float $promoPrice = null;

    public bool $freeFragment = true;

    #[Assert\Choice(choices: ['public', 'private', 'limited'], groups: ['pricing'])]
    public string $salesModel = 'public';

    public const int AUTHOR_SHARE = 70; // % the author keeps
}

<?php declare(strict_types=1);

namespace App\Ebook\Presentation\PublishEbook;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Carries the wizard's accumulated data across steps (stored in the session).
 * No persistence layer yet — publishing is a no-op for now.
 */
final class PublishEbookData
{
    // Step 1 — files are uploaded to a staging area as they are added; here we keep
    // only lightweight references (media ids). They are committed at step 4.
    /** @var list<array{mediaId: string, name: string, size: string, format: string, checksum: string}> */
    public array $files = [];

    public ?string $coverMediaId = null;
    public ?string $coverName = null;

    public function hasFiles(): bool
    {
        return [] !== $this->files;
    }

    // Step 2 — details
    #[Assert\NotBlank(message: 'To pole jest wymagane.', groups: ['details'])]
    #[Assert\Length(max: 200, maxMessage: 'Tytuł może mieć maksymalnie {{ limit }} znaków.', groups: ['details'])]
    public ?string $title = null;

    #[Assert\NotBlank(message: 'To pole jest wymagane.', groups: ['details'])]
    #[Assert\Length(max: 80, maxMessage: 'Autor może mieć maksymalnie {{ limit }} znaków.', groups: ['details'])]
    public ?string $author = null;

    #[Assert\NotBlank(message: 'To pole jest wymagane.', groups: ['details'])]
    public ?string $category = null;

    #[Assert\NotBlank(message: 'To pole jest wymagane.', groups: ['details'])]
    public ?string $language = 'pl';

    #[Assert\NotBlank(message: 'To pole jest wymagane.', groups: ['details'])]
    #[Assert\Length(max: 400, maxMessage: 'Krótki opis może mieć maksymalnie {{ limit }} znaków.', groups: ['details'])]
    public ?string $shortDescription = null;

    #[Assert\NotBlank(message: 'To pole jest wymagane.', groups: ['details'])]
    #[Assert\Length(max: 10000, maxMessage: 'Opis może mieć maksymalnie {{ limit }} znaków.', groups: ['details'])]
    public ?string $description = null;

    /** @var list<array{key: string, value: string}> extra key:value rows shown as a table on the detail page */
    public array $details = [];

    // Step 3 — pricing
    public bool $isFree = false;
    public bool $payWhatYouWant = false; // allow voluntary donations on the detail page (future)

    #[Assert\When(
        expression: 'not this.isFree and not this.payWhatYouWant',
        constraints: [new Assert\NotBlank(message: 'Podaj cenę eBooka.'), new Assert\Positive(message: 'Cena musi być większa od zera.')],
        groups: ['pricing'],
    )]
    public ?float $price = null;

    #[Assert\PositiveOrZero(groups: ['pricing'])]
    public ?float $promoPrice = null;

    public bool $freeFragment = true;

    public const int AUTHOR_SHARE = 90; // % the author keeps
}

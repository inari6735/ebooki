<?php declare(strict_types=1);

namespace App\Ebook\Domain;

use App\Ebook\Domain\Pricing\Pricing;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * An eBook listing. Classic ORM aggregate (deliberate, like User). Pricing is
 * stored as plain scalar columns but always read/written through the {@see Pricing}
 * value object, so price maths never leaks into the entity. `user_id` is a soft
 * reference to the owner in the User context; the cover and files relate to Media.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ebooks')]
#[ORM\UniqueConstraint(name: 'uniq_ebooks_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_ebooks_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_ebooks_category', columns: ['category_id'])]
#[ORM\Index(name: 'idx_ebooks_cover', columns: ['cover_media_id'])]
#[ORM\Index(name: 'idx_ebooks_status_published', columns: ['status', 'published_at'])]
#[ORM\HasLifecycleCallbacks]
class Ebook
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'user_id', type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'cover_media_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Media $cover = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    /** @var Collection<int, EbookFile> */
    #[ORM\OneToMany(mappedBy: 'ebook', targetEntity: EbookFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $files;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(length: 230)]
    private string $slug;

    #[ORM\Column(name: 'author_name', length: 100)]
    private string $authorName;

    #[ORM\Column(length: 8)]
    private string $language;

    #[ORM\Column(name: 'short_description', length: 150, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<array{label: string, value: string}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $attributes = [];

    #[ORM\Column(length: 20, enumType: EbookStatus::class)]
    private EbookStatus $status = EbookStatus::DRAFT;

    #[ORM\Column(name: 'is_free', options: ['default' => false])]
    private bool $isFree = false;

    #[ORM\Column(name: 'pay_what_you_want', options: ['default' => false])]
    private bool $payWhatYouWant = false;

    #[ORM\Column(name: 'price_amount', type: Types::INTEGER, nullable: true)]
    private ?int $priceAmount = null;

    #[ORM\Column(name: 'promo_price_amount', type: Types::INTEGER, nullable: true)]
    private ?int $promoPriceAmount = null;

    #[ORM\Column(length: 3, enumType: Currency::class, options: ['default' => 'PLN'])]
    private Currency $currency = Currency::PLN;

    #[ORM\Column(name: 'published_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $userId,
        string $title,
        string $slug,
        string $authorName,
        string $language,
        Pricing $pricing,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->title = $title;
        $this->slug = $slug;
        $this->authorName = $authorName;
        $this->language = $language;
        $this->files = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->applyPricing($pricing);
    }

    // ── Pricing ─────────────────────────────────────────────────────────────

    public function pricing(): Pricing
    {
        if ($this->isFree) {
            return Pricing::free($this->currency);
        }
        if ($this->payWhatYouWant) {
            return Pricing::payWhatYouWant(
                $this->currency,
                null !== $this->priceAmount ? Money::of($this->priceAmount, $this->currency) : null,
            );
        }

        return Pricing::fixed(
            Money::of((int) $this->priceAmount, $this->currency),
            null !== $this->promoPriceAmount ? Money::of($this->promoPriceAmount, $this->currency) : null,
        );
    }

    public function changePricing(Pricing $pricing): void
    {
        $this->applyPricing($pricing);
        $this->touch();
    }

    private function applyPricing(Pricing $pricing): void
    {
        $this->isFree = $pricing->isFree();
        $this->payWhatYouWant = $pricing->isPayWhatYouWant();
        $this->currency = $pricing->currency();
        $this->priceAmount = $pricing->baseAmountMinor();
        $this->promoPriceAmount = $pricing->promoAmountMinor();
    }

    // ── Detailed info (JSON attributes) ─────────────────────────────────────

    /** @return list<Attribute> */
    public function attributes(): array
    {
        return array_map(static fn (array $row): Attribute => Attribute::fromArray($row), $this->attributes);
    }

    public function setAttributes(Attribute ...$attributes): void
    {
        $this->attributes = array_values(array_map(
            static fn (Attribute $a): array => $a->toArray(),
            $attributes,
        ));
        $this->touch();
    }

    // ── Files ───────────────────────────────────────────────────────────────

    public function addFile(EbookFile $file): void
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $this->touch();
        }
    }

    /** @return list<EbookFile> */
    public function files(): array
    {
        return array_values($this->files->toArray());
    }

    public function primaryFile(): ?EbookFile
    {
        foreach ($this->files as $file) {
            if ($file->isPrimary() && EbookFileRole::FULL === $file->getRole()) {
                return $file;
            }
        }

        return null;
    }

    /** @return list<EbookFileFormat> */
    public function formats(): array
    {
        $formats = [];
        foreach ($this->files as $file) {
            if (EbookFileRole::FULL === $file->getRole()) {
                $formats[$file->getFormat()->value] = $file->getFormat();
            }
        }

        return array_values($formats);
    }

    // ── Lifecycle / mutations ───────────────────────────────────────────────

    public function describe(?string $shortDescription, ?string $description): void
    {
        $this->shortDescription = $shortDescription;
        $this->description = $description;
        $this->touch();
    }

    public function assignCover(?Media $cover): void
    {
        $this->cover = $cover;
        $this->touch();
    }

    public function assignCategory(?Category $category): void
    {
        $this->category = $category;
        $this->touch();
    }

    public function publish(\DateTimeImmutable $at): void
    {
        $this->status = EbookStatus::PUBLISHED;
        $this->publishedAt = $at;
        $this->touch();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Getters ─────────────────────────────────────────────────────────────

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwnerId(): Uuid
    {
        return $this->userId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getStatus(): EbookStatus
    {
        return $this->status;
    }

    public function getCover(): ?Media
    {
        return $this->cover;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

<?php declare(strict_types=1);

namespace App\Ebook\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * eBook taxonomy. Self-referencing `parent` allows sub-categories; the self-FK
 * keeps the documented naming exception (`parent_id`, not `category_id`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'categories')]
#[ORM\UniqueConstraint(name: 'uniq_categories_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_categories_parent', columns: ['parent_id'])]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 140)]
    private string $slug;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        string $name,
        string $slug,
        ?self $parent = null,
        int $position = 0,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->parent = $parent;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }
}

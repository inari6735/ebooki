<?php declare(strict_types=1);

namespace App\Ebook\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Association between an eBook and one of its files in Media, carrying the role
 * (full / sample) and format. This is how an eBook supports several formats
 * (PDF + EPUB) and a free sample — one row per (role, format).
 *
 * A partial unique index (in the migration) enforces at most one primary file
 * per eBook: CREATE UNIQUE INDEX ... ON ebook_files (ebook_id) WHERE is_primary.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ebook_files')]
#[ORM\UniqueConstraint(name: 'uniq_ebook_files_role_format', columns: ['ebook_id', 'role', 'format'])]
#[ORM\UniqueConstraint(name: 'uniq_ebook_files_media', columns: ['media_id'])]
#[ORM\Index(name: 'idx_ebook_files_ebook', columns: ['ebook_id'])]
class EbookFile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Ebook::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'ebook_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Ebook $ebook;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(name: 'media_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Media $media;

    #[ORM\Column(length: 16, enumType: EbookFileFormat::class)]
    private EbookFileFormat $format;

    #[ORM\Column(length: 8, enumType: EbookFileRole::class, options: ['default' => 'full'])]
    private EbookFileRole $role;

    #[ORM\Column(name: 'is_primary', options: ['default' => false])]
    private bool $isPrimary;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        Ebook $ebook,
        Media $media,
        EbookFileFormat $format,
        EbookFileRole $role = EbookFileRole::FULL,
        bool $isPrimary = false,
        int $position = 0,
    ) {
        $this->id = $id;
        $this->ebook = $ebook;
        $this->media = $media;
        $this->format = $format;
        $this->role = $role;
        $this->isPrimary = $isPrimary;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getFormat(): EbookFileFormat
    {
        return $this->format;
    }

    public function getRole(): EbookFileRole
    {
        return $this->role;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function markPrimary(): void
    {
        $this->isPrimary = true;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}

<?php declare(strict_types=1);

namespace App\Ebook\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A generic uploaded file: the raw blob descriptor (where it lives, its name,
 * size, type). Media knows nothing about eBooks — other tables point AT it.
 * `user_id` is a soft reference to the uploader in the User context (FK enforced
 * at the DB level, but not mapped as an ORM association to keep contexts decoupled).
 */
#[ORM\Entity]
#[ORM\Table(name: 'media')]
#[ORM\UniqueConstraint(name: 'uniq_media_disk_path', columns: ['disk', 'path'])]
#[ORM\Index(name: 'idx_media_checksum', columns: ['checksum'])]
#[ORM\Index(name: 'idx_media_user', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class Media
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    private string $disk;

    #[ORM\Column(length: 1024)]
    private string $path;

    #[ORM\Column(name: 'original_name', length: 255)]
    private string $originalName;

    #[ORM\Column(name: 'mime_type', length: 150)]
    private string $mimeType;

    #[ORM\Column(length: 16)]
    private string $extension;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum;

    #[ORM\Column(length: 16, enumType: MediaVisibility::class)]
    private MediaVisibility $visibility;

    #[ORM\Column(length: 16, enumType: MediaStatus::class)]
    private MediaStatus $status;

    #[ORM\Column(name: 'user_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $userId;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        string $disk,
        string $path,
        string $originalName,
        string $mimeType,
        string $extension,
        int $size,
        MediaVisibility $visibility,
        ?Uuid $userId = null,
        ?string $checksum = null,
        MediaStatus $status = MediaStatus::READY,
    ) {
        $this->id = $id;
        $this->disk = $disk;
        $this->path = $path;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->extension = strtolower($extension);
        $this->size = $size;
        $this->visibility = $visibility;
        $this->userId = $userId;
        $this->checksum = $checksum;
        $this->status = $status;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getVisibility(): MediaVisibility
    {
        return $this->visibility;
    }

    public function getStatus(): MediaStatus
    {
        return $this->status;
    }

    public function markReady(): void
    {
        $this->status = MediaStatus::READY;
        $this->touch();
    }

    /** Record that the blob moved to a new storage key (e.g. staging → permanent). */
    public function relocate(string $path): void
    {
        $this->path = $path;
        $this->touch();
    }

    public function getDisk(): string
    {
        return $this->disk;
    }
}

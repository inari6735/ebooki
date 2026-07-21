<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure;

use App\Ebook\Domain\Media;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineMediaRepository implements MediaRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Media $media): void
    {
        $this->entityManager->persist($media);
        $this->entityManager->flush();
    }

    public function get(Uuid $id): ?Media
    {
        return $this->entityManager->find(Media::class, $id);
    }

    public function remove(Media $media): void
    {
        $this->entityManager->remove($media);
        $this->entityManager->flush();
    }

    public function stalePending(\DateTimeImmutable $olderThan): array
    {
        return $this->entityManager
            ->createQuery(sprintf('SELECT m FROM %s m WHERE m.status = :status AND m.createdAt < :before', Media::class))
            ->setParameter('status', MediaStatus::PENDING)
            ->setParameter('before', $olderThan)
            ->getResult();
    }
}

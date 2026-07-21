<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEbookRepository implements EbookRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Ebook $ebook): void
    {
        // EbookFile rows cascade-persist through the Ebook aggregate.
        $this->entityManager->persist($ebook);
        $this->entityManager->flush();
    }

    public function get(Uuid $id): ?Ebook
    {
        return $this->entityManager->find(Ebook::class, $id);
    }

    public function findByOwner(Uuid $ownerId): array
    {
        return $this->entityManager->getRepository(Ebook::class)
            ->findBy(['userId' => $ownerId], ['createdAt' => 'DESC']);
    }

    public function remove(Ebook $ebook): void
    {
        // EbookFile rows cascade-remove through the aggregate; the referenced Media
        // blobs are cleaned up separately (see DeleteEbook).
        $this->entityManager->remove($ebook);
        $this->entityManager->flush();
    }

    public function slugExists(string $slug): bool
    {
        return null !== $this->entityManager->getRepository(Ebook::class)->findOneBy(['slug' => $slug]);
    }
}

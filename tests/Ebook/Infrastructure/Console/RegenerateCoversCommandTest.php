<?php declare(strict_types=1);

namespace App\Tests\Ebook\Infrastructure\Console;

use App\Ebook\Application\GenerateCoverThumbnails;
use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Media;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaVisibility;
use App\Ebook\Domain\Pricing\Pricing;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class RegenerateCoversCommandTest extends KernelTestCase
{
    public function testRegeneratesForOneEbookByEnqueueing(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        [$ebookId, $coverMediaId] = $this->ebookWithCover($container);

        $tester = new CommandTester(
            (new Application(self::$kernel))->find('ebook:regenerate-covers'),
        );
        $tester->execute(['ebook' => $ebookId]);

        $tester->assertCommandIsSuccessful();

        $jobs = [];
        foreach ($container->get('messenger.transport.async')->getSent() as $envelope) {
            if (($m = $envelope->getMessage()) instanceof GenerateCoverThumbnails) {
                $jobs[] = $m;
            }
        }
        self::assertCount(1, $jobs);
        self::assertSame($coverMediaId, $jobs[0]->mediaId);
    }

    public function testRejectsWhenNeitherArgumentNorAllGiven(): void
    {
        self::bootKernel();
        $tester = new CommandTester(
            (new Application(self::$kernel))->find('ebook:regenerate-covers'),
        );

        $exit = $tester->execute([]);

        self::assertSame(2, $exit); // Command::INVALID
        self::assertStringContainsString('--all', $tester->getDisplay());
    }

    /** @return array{0: string, 1: string} [ebookId, coverMediaId] */
    private function ebookWithCover(\Psr\Container\ContainerInterface $container): array
    {
        $ownerId = Uuid::v7();
        $container->get(CommandBus::class)->dispatch(
            new RegisterUser($ownerId->toRfc4122(), 'cmd-'.$ownerId->toRfc4122().'@example.com', 'password123'),
        );

        $mediaId = Uuid::v7();
        $media = new Media($mediaId, 'local', 'covers/'.$mediaId->toRfc4122().'.png', 'c.png', 'image/png', 'png', 10, MediaVisibility::PUBLIC, $ownerId);
        $container->get(MediaRepository::class)->save($media);

        $ebookId = Uuid::v7();
        $ebook = new Ebook($ebookId, $ownerId, 'Tytuł', 'slug-'.substr($ebookId->toRfc4122(), 0, 8), 'Autor', 'pl', Pricing::fixed(Money::of(1000, Currency::PLN)));
        $ebook->assignCover($media);
        $container->get(EbookRepository::class)->save($ebook);

        return [$ebookId->toRfc4122(), $mediaId->toRfc4122()];
    }
}

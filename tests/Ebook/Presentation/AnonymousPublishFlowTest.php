<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Category;
use App\Ebook\Domain\Ebook;
use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * The friendly flow: an anonymous visitor can fill the whole wizard (including
 * uploading files), is asked to sign in only at the final "Opublikuj" click, and
 * the publish then completes automatically once they are logged in.
 */
final class AnonymousPublishFlowTest extends WebTestCase
{
    use StagesEbookFileTrait;

    public function testAnonymousFillsThenSignsInAndTheEbookIsPublished(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'author@example.com', 'password123'),
        );

        // 1. Anonymous can open the wizard (previously this redirected to login).
        $crawler = $client->request('GET', 'https://localhost/wystaw-ebook/1');
        self::assertResponseIsSuccessful();

        // 2. Anonymous stages a file.
        $token = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');
        $this->stageEbookFile($client, $token, 'book.pdf', "%PDF-1.4 test\n");
        self::assertResponseStatusCodeSame(201);

        // Minimal details/pricing so the commit can build a valid eBook.
        $this->fillSession();

        // 3. Finalize while anonymous → redirected to login, nothing published yet.
        $client->request('POST', 'https://localhost/wystaw-ebook/4');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(0, $em->getRepository(Ebook::class)->count([]));

        // 4. Sign in with the target path back to step 4.
        $client->request('GET', 'https://localhost/login?_target_path=/wystaw-ebook/4');
        $client->submitForm('Zaloguj się', ['email' => 'author@example.com', 'password' => 'password123']);
        $client->followRedirect(); // → /wystaw-ebook/4, which auto-finalizes

        // 5. The eBook is now published, no second click needed — landing on
        //    "Wystawione" with the new record highlighted.
        self::assertResponseRedirects();
        self::assertStringContainsString('/panel/wystawione?new=', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(1, $em->getRepository(Ebook::class)->count([]));

        // 6. The list shows the published eBook, flagged "Nowo dodany".
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Skuteczna produktywność');
        self::assertSelectorTextContains('body', 'Opublikowany');
        self::assertSelectorTextContains('body', 'Nowo dodany');
    }

    private function fillSession(): void
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->persist(new Category(Uuid::v7(), 'Rozwój osobisty', 'rozwoj-osobisty'));
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $client = self::getClient();
        $client->request('POST', 'https://localhost/wystaw-ebook/2', [
            'ebook_details' => [
                'title' => 'Skuteczna produktywność',
                'author' => 'Jan Testowy',
                'category' => 'rozwoj-osobisty',
                'language' => 'pl',
                'shortDescription' => 'Krótki opis',
                'description' => 'Dłuższy opis eBooka.',
                '_token' => 'x',
            ],
        ]);
        $client->request('POST', 'https://localhost/wystaw-ebook/3', [
            'ebook_pricing' => ['price' => '40', '_token' => 'x'],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');
        parent::tearDown();
    }
}

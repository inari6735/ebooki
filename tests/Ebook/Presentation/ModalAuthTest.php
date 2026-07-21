<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Category;
use App\Ebook\Domain\Ebook;
use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * The in-page auth modal: an anonymous author fills the wizard, then authenticates
 * over AJAX (register + login return JSON, cookies are set) without leaving the
 * page — and the publish completes as an authenticated request.
 */
final class ModalAuthTest extends WebTestCase
{
    use StagesEbookFileTrait;

    public function testRegisterAndLoginOverAjaxThenPublish(): void
    {
        $client = self::createClient();
        $this->prepareWizard($client);

        // Modal "Załóż konto" → AJAX register returns JSON, no navigation.
        $client->request('POST', 'https://localhost/register', ['registration' => [
            'email' => 'modal@example.com',
            'plainPassword' => ['first' => 'password123', 'second' => 'password123'],
            '_token' => 'csrf-token',
        ]], [], $this->ajax());
        self::assertResponseStatusCodeSame(201);
        self::assertJsonStringEqualsJsonString('{"ok":true}', (string) $client->getResponse()->getContent());

        // …then the modal auto-logs in over AJAX; cookies are set, still JSON.
        $client->request('POST', 'https://localhost/login', [
            'email' => 'modal@example.com',
            'password' => 'password123',
            '_csrf_token' => 'csrf-token',
        ], [], $this->ajax());
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"ok":true}', (string) $client->getResponse()->getContent());
        self::assertNotNull($client->getCookieJar()->get('AUTH_TOKEN'));

        // The modal then submits the finalize form — now an authenticated request.
        $client->request('POST', 'https://localhost/wystaw-ebook/4');
        self::assertResponseRedirects();
        self::assertStringContainsString('/panel/wystawione?new=', (string) $client->getResponse()->headers->get('Location'));
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Ebook::class)->count([]));
    }

    public function testAjaxLoginFailureReturnsJson(): void
    {
        $client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'known@example.com', 'password123'),
        );

        $client->request('POST', 'https://localhost/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
            '_csrf_token' => 'csrf-token',
        ], [], $this->ajax());

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('error', (string) $client->getResponse()->getContent());
    }

    /** @return array<string, string> */
    private function ajax(): array
    {
        return [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ORIGIN' => 'https://localhost',
        ];
    }

    private function prepareWizard(KernelBrowser $client): void
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->persist(new Category(Uuid::v7(), 'Rozwój osobisty', 'rozwoj-osobisty'));
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $crawler = $client->request('GET', 'https://localhost/wystaw-ebook/1');
        $token = $crawler->filter('[data-staged-upload-token-value]')->first()->attr('data-staged-upload-token-value');

        $this->stageEbookFile($client, $token, 'book.pdf', "%PDF-1.4 test\n");
        $client->request('POST', 'https://localhost/wystaw-ebook/2', ['ebook_details' => [
            'title' => 'Test', 'author' => 'Jan', 'category' => 'rozwoj-osobisty', 'language' => 'pl',
            'shortDescription' => 'Krótki', 'description' => 'Opis.', '_token' => 'csrf-token',
        ]]);
        $client->request('POST', 'https://localhost/wystaw-ebook/3', ['ebook_pricing' => ['price' => '40', '_token' => 'x']]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage/test');
        parent::tearDown();
    }
}

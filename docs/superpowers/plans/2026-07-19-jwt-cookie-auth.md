# JWT Cookie Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registration, login, logout and silent-refresh for the whole app (server-rendered Twig), authenticated by a JWT in an HttpOnly cookie — stateless firewall, no PHP sessions for auth.

**Architecture:** `User` bounded context beside the existing `Shared` CQRS kernel. `User` is a classic Doctrine ORM entity (deliberate exception from event sourcing — auth is infrastructure). Registration goes through the existing `CommandBus`. Login issues an access JWT (15 min, cookie `AUTH_TOKEN`) plus a single-use rotating refresh token (7 days, DB-backed, cookie `REFRESH_TOKEN`). A `kernel.request` listener silently rotates tokens before the firewall runs; refresh-token reuse revokes all of the user's refresh tokens.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM 3, lexik/jwt-authentication-bundle (RS256), gesdinet/jwt-refresh-token-bundle, symfony/uid, symfony/rate-limiter, PHPUnit 13 + dama/doctrine-test-bundle.

## Global Constraints

- PHP `>=8.4`, Symfony `8.1.*` — all new files start with `<?php declare(strict_types=1);`.
- Commands implement `App\Shared\Application\Bus\Command` **and** `App\Shared\Application\Transport\SyncTransport` (routes them to the `sync` transport); dispatch only via the `App\Shared\Application\Bus\CommandBus` port.
- Command handlers are tagged `#[AsMessageHandler(bus: 'messenger.bus.command')]` (that bus has `validation` + `doctrine_transaction` middleware).
- Cookies: `AUTH_TOKEN` (JWT, TTL 900 s) and `REFRESH_TOKEN` (TTL 604800 s); both HttpOnly, Secure, SameSite=Lax, path `/`.
- Access JWT claims: `sub` = user UUID, `email`, `roles`; identity claim `username` = email (entity provider loads by `email`).
- Login failure shows ONE generic message: `Invalid email or password.` — never which part was wrong.
- Registration does NOT auto-login; it redirects to `/login` with a success flash.
- Functional tests must use `https://localhost/...` URLs (cookies are `Secure`; BrowserKit drops them over plain http).
- Postgres runs via `docker compose up -d database`; test DB = main DB name + `_test` suffix (configured in `doctrine.yaml`).
- `config/routes/security.yaml` already exists (recipe stub) — logout route goes there.
- Existing conventions: `final readonly` services where possible, constructor property promotion, no annotations/YAML mapping — ORM attributes.

---

### Task 1: Foundation — packages, keypair, service autowiring, test DB

**Files:**
- Modify: `config/services.yaml`
- Modify: `config/packages/lexik_jwt_authentication.yaml` (created by recipe)
- Modify: `phpunit.dist.xml`
- Modify: `.env.test`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: compilable container with `App\` autowiring restored; `lexik_jwt_authentication` configured with cookie extractor; test database `app_test` created; DAMA transaction isolation active for all later functional tests.

- [ ] **Step 1: Install packages**

```bash
composer require lexik/jwt-authentication-bundle gesdinet/jwt-refresh-token-bundle symfony/uid symfony/rate-limiter
composer require --dev dama/doctrine-test-bundle
```

Expected: recipes add `LexikJWTAuthenticationBundle`, `GesdinetJWTRefreshTokenBundle`, `DAMADoctrineTestBundle` to `config/bundles.php`, create `config/packages/lexik_jwt_authentication.yaml` and `config/packages/gesdinet_jwt_refresh_token.yaml`, and append `JWT_*` vars to `.env`.

- [ ] **Step 2: Generate the RS256 keypair**

```bash
php bin/console lexik:jwt:generate-keypair
```

Expected: `config/jwt/private.pem` and `config/jwt/public.pem` created (recipe gitignores them).

- [ ] **Step 3: Restore `App\` autowiring in `config/services.yaml`**

Replace the whole `services:` section body (keep `_defaults` as-is) so it reads:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/Kernel.php'
            - '../src/User/Domain/User.php'

    App\Shared\Application\Bus\CommandBus: '@App\Shared\Infrastructure\MessengerCommandBus'
    App\Shared\Application\Bus\QueryBus: '@App\Shared\Infrastructure\MessengerQueryBus'

    App\Shared\Infrastructure\MessengerCommandBus:
        arguments:
            $commandBus: '@messenger.bus.command'

    App\Shared\Infrastructure\MessengerQueryBus:
        arguments:
            $queryBus: '@messenger.bus.query'
```

(`src/User/Domain/User.php` does not exist yet — the exclude is forward-declared and harmless.)

- [ ] **Step 4: Configure Lexik for cookie extraction**

Overwrite `config/packages/lexik_jwt_authentication.yaml`:

```yaml
lexik_jwt_authentication:
    secret_key: '%env(resolve:JWT_SECRET_KEY)%'
    public_key: '%env(resolve:JWT_PUBLIC_KEY)%'
    pass_phrase: '%env(JWT_PASSPHRASE)%'
    token_ttl: 900
    token_extractors:
        authorization_header:
            enabled: false
        cookie:
            enabled: true
            name: AUTH_TOKEN
```

- [ ] **Step 5: Register the DAMA PHPUnit extension**

In `phpunit.dist.xml`, replace the empty `<extensions>` block:

```xml
    <extensions>
        <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension" />
    </extensions>
```

- [ ] **Step 6: Create and migrate the test database**

```bash
docker compose up -d database
php bin/console doctrine:database:create --env=test --if-not-exists
```

Expected: `Created database "app_test"` (or "already exists").

- [ ] **Step 7: Verify the container compiles in both envs**

```bash
php bin/console cache:clear
php bin/console debug:container App\Shared\Application\Bus\CommandBus
php bin/console cache:clear --env=test
php bin/phpunit
```

Expected: alias resolves to `MessengerCommandBus`; PHPUnit reports "No tests executed" without errors.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock symfony.lock config/ phpunit.dist.xml .env .env.test .gitignore
git commit -m "feat(auth): install JWT stack, restore autowiring, wire test DB"
```

---

### Task 2: User entity + repository port (Domain)

**Files:**
- Create: `src/User/Domain/User.php`
- Create: `src/User/Domain/UserRepository.php`
- Test: `tests/User/Domain/UserTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\User\Domain\User` — `__construct(Symfony\Component\Uid\Uuid $id, string $email)`, `getId(): Uuid`, `getEmail(): string`, `getUserIdentifier(): string` (returns email), `getRoles(): array` (always contains `ROLE_USER`), `getPassword(): string`, `setPassword(string $hashedPassword): void`. Implements `UserInterface` + `PasswordAuthenticatedUserInterface`.
  - `App\User\Domain\UserRepository` — `byEmail(string $email): ?User`, `add(User $user): void`, `emailExists(string $email): bool`.

- [ ] **Step 1: Write the failing test**

`tests/User/Domain/UserTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Domain;

use App\User\Domain\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UserTest extends TestCase
{
    public function testIdentifierIsEmail(): void
    {
        $user = new User(Uuid::v7(), 'reader@example.com');

        self::assertSame('reader@example.com', $user->getUserIdentifier());
        self::assertSame('reader@example.com', $user->getEmail());
    }

    public function testEveryUserHasRoleUser(): void
    {
        $user = new User(Uuid::v7(), 'reader@example.com');

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testPasswordHashIsStored(): void
    {
        $user = new User(Uuid::v7(), 'reader@example.com');
        $user->setPassword('$2y$04$hash');

        self::assertSame('$2y$04$hash', $user->getPassword());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/User/Domain/UserTest.php`
Expected: FAIL — `Class "App\User\Domain\User" not found`.

- [ ] **Step 3: Implement the entity and the port**

`src/User/Domain/User.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Column]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        private readonly Uuid $id,
        #[ORM\Column(length: 180)]
        private readonly string $email,
    ) {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }
}
```

`src/User/Domain/UserRepository.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Domain;

interface UserRepository
{
    public function byEmail(string $email): ?User;

    public function add(User $user): void;

    public function emailExists(string $email): bool;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/User/Domain/UserTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/User/Domain tests/User/Domain
git commit -m "feat(user): User entity and UserRepository port"
```

---

### Task 3: Doctrine repository + users migration (Infrastructure)

**Files:**
- Create: `src/User/Infrastructure/DoctrineUserRepository.php`
- Create: `migrations/` (generated)
- Test: `tests/User/Infrastructure/DoctrineUserRepositoryTest.php`

**Interfaces:**
- Consumes: `App\User\Domain\User`, `App\User\Domain\UserRepository` (Task 2).
- Produces: `App\User\Infrastructure\DoctrineUserRepository implements UserRepository` — the container autowires the `UserRepository` interface to it (single implementation). DB table `users` (uuid PK, unique email, password, roles json).

- [ ] **Step 1: Write the failing integration test**

`tests/User/Infrastructure/DoctrineUserRepositoryTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Infrastructure;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testAddAndFindByEmail(): void
    {
        $user = new User(Uuid::v7(), 'stored@example.com');
        $user->setPassword('hash');

        $this->repository->add($user);

        $found = $this->repository->byEmail('stored@example.com');
        self::assertNotNull($found);
        self::assertTrue($user->getId()->equals($found->getId()));
    }

    public function testByEmailReturnsNullForUnknown(): void
    {
        self::assertNull($this->repository->byEmail('nobody@example.com'));
    }

    public function testEmailExists(): void
    {
        $user = new User(Uuid::v7(), 'taken@example.com');
        $user->setPassword('hash');
        $this->repository->add($user);

        self::assertTrue($this->repository->emailExists('taken@example.com'));
        self::assertFalse($this->repository->emailExists('free@example.com'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/User/Infrastructure/DoctrineUserRepositoryTest.php`
Expected: FAIL — no service implements `UserRepository`.

- [ ] **Step 3: Implement the repository**

`src/User/Infrastructure/DoctrineUserRepository.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function byEmail(string $email): ?User
    {
        return $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);
    }

    public function add(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->byEmail($email);
    }
}
```

- [ ] **Step 4: Generate and run the migration**

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Expected: migration creates table `users` with uuid PK, `email` varchar(180) with unique index `uniq_users_email`, `password`, `roles` json. Inspect the generated file; delete any unrelated statements.

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/User/Infrastructure/DoctrineUserRepositoryTest.php`
Expected: PASS (3 tests). DAMA rolls back between tests.

- [ ] **Step 6: Commit**

```bash
git add src/User/Infrastructure/DoctrineUserRepository.php migrations tests/User/Infrastructure
git commit -m "feat(user): Doctrine user repository and users table migration"
```

---

### Task 4: RegisterUser command + handler (Application)

**Files:**
- Create: `src/User/Application/Command/RegisterUser.php`
- Create: `src/User/Application/Command/RegisterUserHandler.php`
- Create: `tests/User/Doubles/InMemoryUserRepository.php`
- Create: `tests/User/Doubles/FakePasswordHasher.php`
- Test: `tests/User/Application/RegisterUserHandlerTest.php`

**Interfaces:**
- Consumes: `Command`, `SyncTransport`, `CommandBus` (Shared); `User`, `UserRepository` (Task 2).
- Produces:
  - `App\User\Application\Command\RegisterUser` — `__construct(public string $userId, public string $email, public string $plainPassword)`; implements `Command`, `SyncTransport`; carries Validator constraints enforced by the command-bus `validation` middleware.
  - `App\User\Application\Command\RegisterUserHandler` — `__invoke(RegisterUser $command): void`; hashes, persists.
  - Test doubles reusable by later tasks: `InMemoryUserRepository` (implements `UserRepository`, public array `$users`), `FakePasswordHasher` (implements `UserPasswordHasherInterface`, hash = `'hashed:' . $plain`).

- [ ] **Step 1: Write the failing test and the doubles**

`tests/User/Doubles/InMemoryUserRepository.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Doubles;

use App\User\Domain\User;
use App\User\Domain\UserRepository;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> keyed by email */
    public array $users = [];

    public function byEmail(string $email): ?User
    {
        return $this->users[$email] ?? null;
    }

    public function add(User $user): void
    {
        $this->users[$user->getEmail()] = $user;
    }

    public function emailExists(string $email): bool
    {
        return isset($this->users[$email]);
    }
}
```

`tests/User/Doubles/FakePasswordHasher.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Doubles;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class FakePasswordHasher implements UserPasswordHasherInterface
{
    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        return 'hashed:' . $plainPassword;
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $user->getPassword() === 'hashed:' . $plainPassword;
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}
```

`tests/User/Application/RegisterUserHandlerTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Application;

use App\Tests\User\Doubles\FakePasswordHasher;
use App\Tests\User\Doubles\InMemoryUserRepository;
use App\User\Application\Command\RegisterUser;
use App\User\Application\Command\RegisterUserHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RegisterUserHandlerTest extends TestCase
{
    public function testRegistersUserWithHashedPassword(): void
    {
        $repository = new InMemoryUserRepository();
        $handler = new RegisterUserHandler($repository, new FakePasswordHasher());
        $userId = Uuid::v7()->toRfc4122();

        $handler(new RegisterUser($userId, 'new@example.com', 's3cretpass'));

        $user = $repository->byEmail('new@example.com');
        self::assertNotNull($user);
        self::assertSame($userId, $user->getId()->toRfc4122());
        self::assertSame('hashed:s3cretpass', $user->getPassword());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/User/Application/RegisterUserHandlerTest.php`
Expected: FAIL — `Class "App\User\Application\Command\RegisterUser" not found`.

- [ ] **Step 3: Implement command and handler**

`src/User/Application/Command/RegisterUser.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Application\Command;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\SyncTransport;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterUser implements Command, SyncTransport
{
    public function __construct(
        #[Assert\Uuid]
        public string $userId,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $plainPassword,
    ) {
    }
}
```

`src/User/Application/Command/RegisterUserHandler.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Application\Command;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(RegisterUser $command): void
    {
        $user = new User(Uuid::fromString($command->userId), $command->email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));

        $this->users->add($user);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/User/Application/RegisterUserHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: Verify the handler is wired to the command bus**

Run: `php bin/console debug:messenger messenger.bus.command`
Expected: `App\User\Application\Command\RegisterUser` handled by `RegisterUserHandler`.

- [ ] **Step 6: Commit**

```bash
git add src/User/Application tests/User/Application tests/User/Doubles
git commit -m "feat(user): RegisterUser command and handler"
```

---

### Task 5: Registration page (Presentation) + /login stub

**Files:**
- Create: `src/User/Presentation/RegistrationController.php`
- Create: `src/User/Presentation/SecurityController.php`
- Create: `src/User/Presentation/Form/RegistrationFormType.php`
- Create: `src/User/Presentation/Validator/UniqueEmail.php`
- Create: `src/User/Presentation/Validator/UniqueEmailValidator.php`
- Create: `templates/user/register.html.twig`
- Create: `templates/user/login.html.twig`
- Test: `tests/User/Presentation/RegistrationTest.php`

**Interfaces:**
- Consumes: `CommandBus` (Shared), `RegisterUser` (Task 4), `UserRepository::emailExists()` (Task 2/3).
- Produces: routes `app_register` (`GET|POST /register`) and `app_login` (`GET /login`, form POST target added in Task 7). Templates extend `base.html.twig`. Later tasks rely on route names `app_register`, `app_login`.

- [ ] **Step 1: Write the failing functional test**

`tests/User/Presentation/RegistrationTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\User\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testRegistrationFormRenders(): void
    {
        $this->client->request('GET', 'https://localhost/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="registration[email]"]');
    }

    public function testSuccessfulRegistrationRedirectsToLogin(): void
    {
        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'newreader@example.com',
            'registration[plainPassword][first]' => 'password123',
            'registration[plainPassword][second]' => 'password123',
        ]);

        self::assertResponseRedirects('/login');

        $repository = self::getContainer()->get(UserRepository::class);
        $user = $repository->byEmail('newreader@example.com');
        self::assertNotNull($user);
        self::assertNotSame('', $user->getPassword());
        self::assertStringStartsNotWith('password123', $user->getPassword());
    }

    public function testDuplicateEmailShowsFormError(): void
    {
        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'dupe@example.com',
            'registration[plainPassword][first]' => 'password123',
            'registration[plainPassword][second]' => 'password123',
        ]);

        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'dupe@example.com',
            'registration[plainPassword][first]' => 'password123',
            'registration[plainPassword][second]' => 'password123',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'already registered');
    }

    public function testShortPasswordIsRejected(): void
    {
        $this->client->request('GET', 'https://localhost/register');
        $this->client->submitForm('Register', [
            'registration[email]' => 'short@example.com',
            'registration[plainPassword][first]' => 'short',
            'registration[plainPassword][second]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            self::getContainer()->get(UserRepository::class)->byEmail('short@example.com'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/User/Presentation/RegistrationTest.php`
Expected: FAIL — 404 on `/register`.

- [ ] **Step 3: Implement constraint, form, controllers, templates**

`src/User/Presentation/Validator/UniqueEmail.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Presentation\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UniqueEmail extends Constraint
{
    public string $message = 'This email is already registered.';
}
```

`src/User/Presentation/Validator/UniqueEmailValidator.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Presentation\Validator;

use App\User\Domain\UserRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueEmailValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueEmail) {
            throw new UnexpectedTypeException($constraint, UniqueEmail::class);
        }

        if (null === $value || '' === $value || !\is_string($value)) {
            return;
        }

        if ($this->users->emailExists($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
```

`src/User/Presentation/Form/RegistrationFormType.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Presentation\Form;

use App\User\Presentation\Validator\UniqueEmail;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Email(),
                    new UniqueEmail(),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The password fields must match.',
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat password'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 8, max: 4096),
                ],
            ]);
    }

    public function getBlockPrefix(): string
    {
        return 'registration';
    }
}
```

`src/User/Presentation/RegistrationController.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use App\User\Presentation\Form\RegistrationFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, CommandBus $commandBus): Response
    {
        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, plainPassword: string} $data */
            $data = $form->getData();

            try {
                $commandBus->dispatch(new RegisterUser(
                    Uuid::v7()->toRfc4122(),
                    $data['email'],
                    $data['plainPassword'],
                ));

                $this->addFlash('success', 'Account created. You can now log in.');

                return $this->redirectToRoute('app_login');
            } catch (UniqueConstraintViolationException) {
                // Registration race: the UNIQUE index is the last line of defense.
                $form->get('email')->addError(new FormError('This email is already registered.'));
            }
        }

        return $this->render('user/register.html.twig', [
            'form' => $form,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
```

`src/User/Presentation/SecurityController.php` (login POST handling arrives in Task 7; logout route is firewall-intercepted and defined in Task 9):

```php
<?php declare(strict_types=1);

namespace App\User\Presentation;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        return $this->render('user/login.html.twig', [
            'target_path' => $request->query->get('_target_path', ''),
        ]);
    }
}
```

`templates/user/register.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Register{% endblock %}

{% block body %}
    <h1>Register</h1>

    {{ form_start(form) }}
        {{ form_row(form.email) }}
        {{ form_row(form.plainPassword.first) }}
        {{ form_row(form.plainPassword.second) }}
        <button type="submit">Register</button>
    {{ form_end(form) }}

    <p><a href="{{ path('app_login') }}">Already have an account? Log in</a></p>
{% endblock %}
```

`templates/user/login.html.twig` (POST target `/login` becomes active in Task 7 — the form is complete now so Task 7 only touches PHP):

```twig
{% extends 'base.html.twig' %}

{% block title %}Log in{% endblock %}

{% block body %}
    <h1>Log in</h1>

    {% for message in app.flashes('success') %}
        <p class="flash-success">{{ message }}</p>
    {% endfor %}
    {% for message in app.flashes('error') %}
        <p class="flash-error">{{ message }}</p>
    {% endfor %}

    <form method="post" action="{{ path('app_login') }}">
        <input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">
        <input type="hidden" name="_target_path" value="{{ target_path }}">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus>

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Log in</button>
    </form>

    <p><a href="{{ path('app_register') }}">No account yet? Register</a></p>
{% endblock %}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/User/Presentation/RegistrationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/User/Presentation templates/user tests/User/Presentation
git commit -m "feat(user): registration page and login form stub"
```

---

### Task 6: Refresh token infrastructure — entity, rotation, cookies

**Files:**
- Create: `src/User/Infrastructure/Security/RefreshToken.php`
- Create: `src/User/Infrastructure/Security/RefreshTokenRotator.php`
- Create: `src/User/Infrastructure/Security/TokenCookieFactory.php`
- Modify: `config/packages/gesdinet_jwt_refresh_token.yaml`
- Create: `migrations/` (generated)
- Test: `tests/User/Infrastructure/RefreshTokenRotatorTest.php`

**Interfaces:**
- Consumes: `User`, `UserRepository` (Tasks 2–3).
- Produces:
  - `App\User\Infrastructure\Security\RefreshToken` — gesdinet entity, table `refresh_tokens`.
  - `App\User\Infrastructure\Security\RefreshTokenRotator` —
    `issueFor(User $user): RefreshTokenInterface`,
    `rotate(RefreshTokenInterface $used): ?RefreshTokenInterface` (invalidate old + issue new; `null` when the user vanished),
    `revokeAllFor(string $username): void`.
    Rotation INVALIDATES (sets `valid` into the past) rather than deletes, so a reused rotated token is still findable → theft detection.
  - `App\User\Infrastructure\Security\TokenCookieFactory` — constants `AUTH_COOKIE = 'AUTH_TOKEN'`, `REFRESH_COOKIE = 'REFRESH_TOKEN'`; `authCookie(string $jwt): Cookie`, `refreshCookie(string $token): Cookie`, `expiredAuthCookie(): Cookie`, `expiredRefreshCookie(): Cookie`. All cookies HttpOnly, Secure, SameSite=Lax, path `/`.

- [ ] **Step 1: Configure gesdinet and create the entity + migration**

Overwrite `config/packages/gesdinet_jwt_refresh_token.yaml`:

```yaml
gesdinet_jwt_refresh_token:
    refresh_token_class: App\User\Infrastructure\Security\RefreshToken
    ttl: 604800
    single_use: true
```

`src/User/Infrastructure/Security/RefreshToken.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Doctrine\ORM\Mapping as ORM;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken as BaseRefreshToken;

#[ORM\Entity]
#[ORM\Table(name: 'refresh_tokens')]
class RefreshToken extends BaseRefreshToken
{
}
```

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

Expected: migration creates `refresh_tokens` (id, refresh_token unique, username, valid).

- [ ] **Step 2: Write the failing test**

`tests/User/Infrastructure/RefreshTokenRotatorTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Infrastructure;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use App\User\Infrastructure\Security\RefreshTokenRotator;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefreshTokenRotatorTest extends KernelTestCase
{
    private RefreshTokenRotator $rotator;
    private RefreshTokenManagerInterface $manager;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rotator = self::getContainer()->get(RefreshTokenRotator::class);
        $this->manager = self::getContainer()->get(RefreshTokenManagerInterface::class);

        $this->user = new User(Uuid::v7(), 'rotate@example.com');
        $this->user->setPassword('hash');
        self::getContainer()->get(UserRepository::class)->add($this->user);
    }

    public function testIssueForCreatesValidToken(): void
    {
        $token = $this->rotator->issueFor($this->user);

        $stored = $this->manager->get($token->getRefreshToken());
        self::assertNotNull($stored);
        self::assertTrue($stored->isValid());
        self::assertSame('rotate@example.com', $stored->getUsername());
    }

    public function testRotateInvalidatesOldAndIssuesNew(): void
    {
        $old = $this->rotator->issueFor($this->user);

        $new = $this->rotator->rotate($old);

        self::assertNotNull($new);
        self::assertNotSame($old->getRefreshToken(), $new->getRefreshToken());

        $oldStored = $this->manager->get($old->getRefreshToken());
        self::assertNotNull($oldStored, 'rotated token must remain findable for theft detection');
        self::assertFalse($oldStored->isValid());
        self::assertTrue($this->manager->get($new->getRefreshToken())->isValid());
    }

    public function testRevokeAllForDeletesEveryToken(): void
    {
        $a = $this->rotator->issueFor($this->user);
        $b = $this->rotator->issueFor($this->user);

        $this->rotator->revokeAllFor('rotate@example.com');

        self::assertNull($this->manager->get($a->getRefreshToken()));
        self::assertNull($this->manager->get($b->getRefreshToken()));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php bin/phpunit tests/User/Infrastructure/RefreshTokenRotatorTest.php`
Expected: FAIL — `RefreshTokenRotator` not found.

- [ ] **Step 4: Implement rotator and cookie factory**

`src/User/Infrastructure/Security/RefreshTokenRotator.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;

final readonly class RefreshTokenRotator
{
    public const int TTL = 604800; // 7 days, mirrors gesdinet_jwt_refresh_token.ttl

    public function __construct(
        private RefreshTokenGeneratorInterface $generator,
        private RefreshTokenManagerInterface $manager,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function issueFor(User $user): RefreshTokenInterface
    {
        $token = $this->generator->createForUserWithTtl($user, self::TTL);
        $this->manager->save($token);

        return $token;
    }

    public function rotate(RefreshTokenInterface $used): ?RefreshTokenInterface
    {
        $user = $this->users->byEmail((string) $used->getUsername());
        if (null === $user) {
            $this->manager->delete($used);

            return null;
        }

        // Invalidate instead of delete: a later reuse of this token is provable theft.
        $used->setValid(new \DateTimeImmutable('-1 second'));
        $this->manager->save($used);

        return $this->issueFor($user);
    }

    public function revokeAllFor(string $username): void
    {
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s rt WHERE rt.username = :username', RefreshToken::class),
        )->execute(['username' => $username]);
    }
}
```

`src/User/Infrastructure/Security/TokenCookieFactory.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Cookie;

final readonly class TokenCookieFactory
{
    public const string AUTH_COOKIE = 'AUTH_TOKEN';
    public const string REFRESH_COOKIE = 'REFRESH_TOKEN';

    private const int AUTH_TTL = 900;      // mirrors lexik token_ttl
    private const int REFRESH_TTL = RefreshTokenRotator::TTL;

    public function authCookie(string $jwt): Cookie
    {
        return $this->cookie(self::AUTH_COOKIE, $jwt, time() + self::AUTH_TTL);
    }

    public function refreshCookie(string $token): Cookie
    {
        return $this->cookie(self::REFRESH_COOKIE, $token, time() + self::REFRESH_TTL);
    }

    public function expiredAuthCookie(): Cookie
    {
        return $this->cookie(self::AUTH_COOKIE, '', 1);
    }

    public function expiredRefreshCookie(): Cookie
    {
        return $this->cookie(self::REFRESH_COOKIE, '', 1);
    }

    private function cookie(string $name, string $value, int $expire): Cookie
    {
        return Cookie::create(
            $name,
            $value,
            $expire,
            '/',
            null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/User/Infrastructure/RefreshTokenRotatorTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/User/Infrastructure/Security config/packages/gesdinet_jwt_refresh_token.yaml migrations tests/User/Infrastructure
git commit -m "feat(user): refresh token entity, rotation with theft-detectable invalidation, cookie factory"
```

---

### Task 7: Login — authenticator, success handler, firewall

**Files:**
- Create: `src/User/Infrastructure/Security/FormLoginAuthenticator.php`
- Create: `src/User/Infrastructure/Security/JwtCookieSuccessHandler.php`
- Modify: `config/packages/security.yaml`
- Test: `tests/User/Presentation/LoginTest.php`

**Interfaces:**
- Consumes: `TokenCookieFactory`, `RefreshTokenRotator` (Task 6); route `app_login` and login form field names `email`, `password`, `_csrf_token`, `_target_path` (Task 5).
- Produces:
  - `JwtCookieSuccessHandler implements AuthenticationSuccessHandlerInterface` — `onAuthenticationSuccess(Request, TokenInterface): Response`; mints JWT (`createFromPayload` with `sub` + `email`) + refresh token, attaches both cookies, redirects to `_target_path` or `/`.
  - Firewall `main` is `stateless: true` with the `jwt` authenticator (cookie extractor) + this form authenticator. Task 8 relies on the firewall config exactly as written here.

- [ ] **Step 1: Write the failing functional test**

`tests/User/Presentation/LoginTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class LoginTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'login@example.com', 'password123'),
        );
    }

    private function submitLogin(string $email, string $password): void
    {
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function testSuccessfulLoginSetsBothCookiesAndRedirects(): void
    {
        $this->submitLogin('login@example.com', 'password123');

        self::assertResponseRedirects('/');

        $cookies = $this->client->getResponse()->headers->getCookies();
        $names = array_map(fn ($c) => $c->getName(), $cookies);
        self::assertContains('AUTH_TOKEN', $names);
        self::assertContains('REFRESH_TOKEN', $names);

        foreach ($cookies as $cookie) {
            self::assertTrue($cookie->isHttpOnly());
            self::assertTrue($cookie->isSecure());
            self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        }
    }

    public function testFailedLoginShowsGenericError(): void
    {
        $this->submitLogin('login@example.com', 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid email or password.');

        $names = array_map(
            fn ($c) => $c->getName(),
            $this->client->getResponse()->headers->getCookies(),
        );
        self::assertNotContains('AUTH_TOKEN', $names);
    }

    public function testUnknownEmailShowsSameGenericError(): void
    {
        $this->submitLogin('ghost@example.com', 'password123');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid email or password.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/User/Presentation/LoginTest.php`
Expected: FAIL — POST /login returns 405 (only GET route exists).

- [ ] **Step 3: Implement success handler and authenticator**

`src/User/Infrastructure/Security/JwtCookieSuccessHandler.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class JwtCookieSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenRotator $refreshTokens,
        private TokenCookieFactory $cookies,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();

        $jwt = $this->jwtManager->createFromPayload($user, [
            'sub' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
        ]);
        $refreshToken = $this->refreshTokens->issueFor($user);

        $targetPath = (string) $request->request->get('_target_path', '');
        // Only relative paths: never redirect off-site.
        if ('' === $targetPath || !str_starts_with($targetPath, '/') || str_starts_with($targetPath, '//')) {
            $targetPath = '/';
        }

        $response = new RedirectResponse($targetPath);
        $response->headers->setCookie($this->cookies->authCookie($jwt));
        $response->headers->setCookie($this->cookies->refreshCookie($refreshToken->getRefreshToken()));

        return $response;
    }
}
```

`src/User/Infrastructure/Security/FormLoginAuthenticator.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class FormLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly JwtCookieSuccessHandler $successHandler,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && '/login' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $email = (string) $request->request->get('email', '');
        $password = (string) $request->request->get('password', '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [new CsrfTokenBadge('authenticate', $csrfToken)],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // One generic message — never reveal whether email or password was wrong.
        $request->getSession()->getFlashBag()->add('error', 'Invalid email or password.');

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
```

- [ ] **Step 4: Configure the firewall**

Replace the `providers`, `firewalls` and `access_control` sections of `config/packages/security.yaml` (keep `password_hashers` and `when@test` as they are):

```yaml
    providers:
        app_user_provider:
            entity:
                class: App\User\Domain\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_profiler|_wdt|assets|build)/
            security: false
        main:
            stateless: true
            provider: app_user_provider
            custom_authenticators:
                - App\User\Infrastructure\Security\FormLoginAuthenticator
            jwt: ~
            login_throttling:
                max_attempts: 5

    access_control:
        - { path: ^/register, roles: PUBLIC_ACCESS }
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/courses/new, roles: ROLE_USER }
        - { path: ^/courses/\S+/(rename|publish), roles: ROLE_USER }
        - { path: ^/courses, roles: PUBLIC_ACCESS }
```

(The `/courses` rules reference routes that do not exist yet — they take effect when the Course context lands.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php bin/phpunit tests/User/Presentation/LoginTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the whole suite**

Run: `php bin/phpunit`
Expected: all green (registration tests still pass under the new firewall).

- [ ] **Step 7: Commit**

```bash
git add src/User/Infrastructure/Security config/packages/security.yaml tests/User/Presentation/LoginTest.php
git commit -m "feat(user): stateless JWT-cookie login with form authenticator"
```

---

### Task 8: Silent refresh + entry point

**Files:**
- Create: `src/User/Infrastructure/Security/SilentRefreshListener.php`
- Create: `src/User/Infrastructure/Security/LoginEntryPoint.php`
- Modify: `config/packages/security.yaml` (add `entry_point`)
- Test: `tests/User/Presentation/SilentRefreshTest.php`
- Test: `tests/User/Infrastructure/LoginEntryPointTest.php`

**Interfaces:**
- Consumes: `TokenCookieFactory`, `RefreshTokenRotator` (Task 6), `JWTTokenManagerInterface` payload shape (Task 7), `UserRepository` (Task 2), gesdinet `RefreshTokenManagerInterface`.
- Produces: transparent token rotation on any request; `LoginEntryPoint implements AuthenticationEntryPointInterface` redirecting to `/login?_target_path=<uri>`. Task 9's logout relies on `TokenCookieFactory` expired-cookie methods only.

- [ ] **Step 1: Write the failing tests**

`tests/User/Presentation/SilentRefreshTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class SilentRefreshTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'silent@example.com', 'password123'),
        );
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => 'silent@example.com',
            'password' => 'password123',
        ]);
    }

    private function refreshCookieValue(): string
    {
        return $this->client->getCookieJar()->get('REFRESH_TOKEN', '/', 'localhost')->getValue();
    }

    public function testMissingAccessTokenIsSilentlyReissued(): void
    {
        $oldRefresh = $this->refreshCookieValue();
        $this->client->getCookieJar()->expire('AUTH_TOKEN', '/', 'localhost');

        $this->client->request('GET', 'https://localhost/login');

        self::assertResponseIsSuccessful();
        $names = array_map(
            fn ($c) => $c->getName(),
            $this->client->getResponse()->headers->getCookies(),
        );
        self::assertContains('AUTH_TOKEN', $names, 'a fresh access token must be attached');
        self::assertContains('REFRESH_TOKEN', $names, 'the refresh token must rotate');
        self::assertNotSame($oldRefresh, $this->refreshCookieValue());
    }

    public function testReusedRefreshTokenRevokesEverything(): void
    {
        $stolen = $this->refreshCookieValue();

        // Legitimate rotation consumes $stolen.
        $this->client->getCookieJar()->expire('AUTH_TOKEN', '/', 'localhost');
        $this->client->request('GET', 'https://localhost/login');
        $current = $this->refreshCookieValue();
        self::assertNotSame($stolen, $current);

        // Attacker replays the consumed token.
        $this->client->getCookieJar()->expire('AUTH_TOKEN', '/', 'localhost');
        $this->client->getCookieJar()->expire('REFRESH_TOKEN', '/', 'localhost');
        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie('REFRESH_TOKEN', $stolen, null, '/', 'localhost', true, true),
        );
        $this->client->request('GET', 'https://localhost/login');

        $manager = self::getContainer()->get(RefreshTokenManagerInterface::class);
        self::assertNull($manager->get($stolen), 'reused token must be gone');
        self::assertNull($manager->get($current), 'ALL tokens of the user must be revoked');
    }
}
```

`tests/User/Infrastructure/LoginEntryPointTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Infrastructure;

use App\User\Infrastructure\Security\LoginEntryPoint;
use App\User\Infrastructure\Security\TokenCookieFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LoginEntryPointTest extends TestCase
{
    public function testRedirectsToLoginPreservingTargetPath(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('app_login', ['_target_path' => '/courses/new'])
            ->willReturn('/login?_target_path=%2Fcourses%2Fnew');

        $entryPoint = new LoginEntryPoint($urlGenerator, new TokenCookieFactory());
        $response = $entryPoint->start(Request::create('https://localhost/courses/new'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login?_target_path=%2Fcourses%2Fnew', $response->getTargetUrl());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/User/Presentation/SilentRefreshTest.php tests/User/Infrastructure/LoginEntryPointTest.php`
Expected: FAIL — no new AUTH_TOKEN cookie on response / `LoginEntryPoint` not found.

- [ ] **Step 3: Implement listener and entry point**

`src/User/Infrastructure/Security/SilentRefreshListener.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs BEFORE the firewall (priority 16 > security's 8): when the access JWT is
 * missing/expired but a valid refresh token is present, rotate in-flight so the
 * request proceeds authenticated and the user never sees a redirect.
 */
final class SilentRefreshListener
{
    private const string PENDING_COOKIES = '_silent_refresh_cookies';

    public function __construct(
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly RefreshTokenRotator $rotator,
        private readonly TokenCookieFactory $cookies,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->hasUsableAccessToken($request)) {
            return;
        }

        $rawRefresh = (string) $request->cookies->get(TokenCookieFactory::REFRESH_COOKIE, '');
        if ('' === $rawRefresh) {
            return; // anonymous — the entry point handles protected routes
        }

        $stored = $this->refreshTokenManager->get($rawRefresh);
        if (null === $stored) {
            $this->schedule($request, [$this->cookies->expiredAuthCookie(), $this->cookies->expiredRefreshCookie()]);

            return;
        }

        if (!$stored->isValid()) {
            // A rotated (single-use) token came back: treat as theft.
            $this->rotator->revokeAllFor((string) $stored->getUsername());
            $this->schedule($request, [$this->cookies->expiredAuthCookie(), $this->cookies->expiredRefreshCookie()]);

            return;
        }

        $newRefresh = $this->rotator->rotate($stored);
        if (null === $newRefresh) {
            $this->schedule($request, [$this->cookies->expiredAuthCookie(), $this->cookies->expiredRefreshCookie()]);

            return;
        }

        $user = $newRefresh->getUser();
        \assert($user instanceof \App\User\Domain\User);
        $jwt = $this->jwtManager->createFromPayload($user, [
            'sub' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
        ]);

        // Let the firewall (which runs next) authenticate this very request.
        $request->cookies->set(TokenCookieFactory::AUTH_COOKIE, $jwt);
        $this->schedule($request, [
            $this->cookies->authCookie($jwt),
            $this->cookies->refreshCookie($newRefresh->getRefreshToken()),
        ]);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        /** @var list<Cookie> $pending */
        $pending = $event->getRequest()->attributes->get(self::PENDING_COOKIES, []);
        foreach ($pending as $cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }

    private function hasUsableAccessToken(Request $request): bool
    {
        $jwt = (string) $request->cookies->get(TokenCookieFactory::AUTH_COOKIE, '');
        if ('' === $jwt) {
            return false;
        }

        try {
            $this->jwtEncoder->decode($jwt);

            return true;
        } catch (JWTDecodeFailureException) {
            return false; // expired or invalid — try the refresh path
        }
    }

    /** @param list<Cookie> $cookies */
    private function schedule(Request $request, array $cookies): void
    {
        $request->attributes->set(self::PENDING_COOKIES, $cookies);
    }
}
```

Note: gesdinet's `RefreshTokenInterface::getUser()` availability differs between versions — if it is absent, load the user via `UserRepository::byEmail((string) $newRefresh->getUsername())` (inject `UserRepository`) and keep the rest identical.

`src/User/Infrastructure/Security/LoginEntryPoint.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TokenCookieFactory $cookies,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = new RedirectResponse(
            $this->urlGenerator->generate('app_login', ['_target_path' => $request->getRequestUri()]),
        );
        $response->headers->setCookie($this->cookies->expiredAuthCookie());
        $response->headers->setCookie($this->cookies->expiredRefreshCookie());

        return $response;
    }
}
```

- [ ] **Step 4: Register the entry point**

In `config/packages/security.yaml`, inside `firewalls.main`, add:

```yaml
            entry_point: App\User\Infrastructure\Security\LoginEntryPoint
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php bin/phpunit tests/User/Presentation/SilentRefreshTest.php tests/User/Infrastructure/LoginEntryPointTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/User/Infrastructure/Security config/packages/security.yaml tests/User
git commit -m "feat(user): silent refresh with rotation, theft detection, login entry point"
```

---

### Task 9: Logout + navigation

**Files:**
- Modify: `config/routes/security.yaml`
- Modify: `config/packages/security.yaml` (logout section)
- Create: `src/User/Infrastructure/Security/CookieLogout.php`
- Modify: `templates/base.html.twig`
- Test: `tests/User/Presentation/LogoutTest.php`

**Interfaces:**
- Consumes: `TokenCookieFactory`, `RefreshTokenRotator::revokeAllFor()` (Task 6), firewall config (Tasks 7–8).
- Produces: route `app_logout` (`POST /logout`, CSRF-protected, firewall-intercepted — no controller); nav bar in `base.html.twig` showing login/register links or user email + logout button.

- [ ] **Step 1: Write the failing functional test**

`tests/User/Presentation/LogoutTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use App\User\Infrastructure\Security\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class LogoutTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'bye@example.com', 'password123'),
        );
        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => 'bye@example.com',
            'password' => 'password123',
        ]);
        $this->client->followRedirect();
    }

    public function testNavShowsUserEmailWhenLoggedIn(): void
    {
        self::assertSelectorTextContains('nav', 'bye@example.com');
    }

    public function testLogoutClearsCookiesAndRevokesRefreshTokens(): void
    {
        $this->client->submitForm('Log out');

        self::assertResponseRedirects('/login');

        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if (\in_array($cookie->getName(), ['AUTH_TOKEN', 'REFRESH_TOKEN'], true)) {
                self::assertLessThan(time(), $cookie->getExpiresTime(), $cookie->getName() . ' must be expired');
            }
        }

        $count = self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery(sprintf('SELECT COUNT(rt) FROM %s rt WHERE rt.username = :u', RefreshToken::class))
            ->setParameter('u', 'bye@example.com')
            ->getSingleScalarResult();
        self::assertSame(0, (int) $count);

        $this->client->followRedirect();
        self::assertSelectorTextContains('nav', 'Log in');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/User/Presentation/LogoutTest.php`
Expected: FAIL — no `nav` element / no logout form.

- [ ] **Step 3: Implement route, firewall logout, listener, nav**

Append to `config/routes/security.yaml`:

```yaml
app_logout:
    path: /logout
    methods: POST
```

In `config/packages/security.yaml`, inside `firewalls.main`, add:

```yaml
            logout:
                path: app_logout
                enable_csrf: true
```

(`logout` CSRF uses the stateless token id `logout` already declared in `config/packages/csrf.yaml`.)

`src/User/Infrastructure/Security/CookieLogout.php`:

```php
<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class CookieLogout
{
    public function __construct(
        private RefreshTokenRotator $refreshTokens,
        private TokenCookieFactory $cookies,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (null !== $user) {
            $this->refreshTokens->revokeAllFor($user->getUserIdentifier());
        }

        $response = new RedirectResponse($this->urlGenerator->generate('app_login'));
        $response->headers->setCookie($this->cookies->expiredAuthCookie());
        $response->headers->setCookie($this->cookies->expiredRefreshCookie());

        $event->setResponse($response);
    }
}
```

In `templates/base.html.twig`, replace `<body>`'s content:

```twig
    <body>
        <nav>
            {% if app.user %}
                <span>{{ app.user.userIdentifier }}</span>
                <form method="post" action="{{ path('app_logout') }}" style="display:inline">
                    <input type="hidden" name="_csrf_token" value="{{ csrf_token('logout') }}">
                    <button type="submit">Log out</button>
                </form>
            {% else %}
                <a href="{{ path('app_login') }}">Log in</a>
                <a href="{{ path('app_register') }}">Register</a>
            {% endif %}
        </nav>
        {% block body %}{% endblock %}
    </body>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/User/Presentation/LogoutTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full suite**

Run: `php bin/phpunit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add config/routes/security.yaml config/packages/security.yaml src/User/Infrastructure/Security/CookieLogout.php templates/base.html.twig tests/User/Presentation/LogoutTest.php
git commit -m "feat(user): CSRF-protected logout clearing cookies and revoking refresh tokens"
```

---

### Task 10: Login throttling verification + docs

**Files:**
- Test: `tests/User/Presentation/LoginThrottlingTest.php`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: firewall `login_throttling: max_attempts: 5` (Task 7), login flow (Task 7).
- Produces: regression coverage for brute-force limiting; updated project docs.

- [ ] **Step 1: Write the failing test**

`tests/User/Presentation/LoginThrottlingTest.php`:

```php
<?php declare(strict_types=1);

namespace App\Tests\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class LoginThrottlingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(CommandBus::class)->dispatch(
            new RegisterUser(Uuid::v7()->toRfc4122(), 'throttle@example.com', 'password123'),
        );
    }

    public function testSixthAttemptIsThrottledEvenWithCorrectPassword(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', 'https://localhost/login');
            $this->client->submitForm('Log in', [
                'email' => 'throttle@example.com',
                'password' => 'wrong-' . $i,
            ]);
        }

        $this->client->request('GET', 'https://localhost/login');
        $this->client->submitForm('Log in', [
            'email' => 'throttle@example.com',
            'password' => 'password123',
        ]);

        self::assertResponseRedirects('/login');
        $names = array_map(
            fn ($c) => $c->getName(),
            $this->client->getResponse()->headers->getCookies(),
        );
        self::assertNotContains('AUTH_TOKEN', $names, 'throttled login must not issue tokens');
    }
}
```

Note: the throttling limiter stores counters in `cache.app`; if the test env cache persists between runs, add `php bin/console cache:pool:clear cache.app --env=test` before the suite (document in CLAUDE.md if needed).

- [ ] **Step 2: Run test — verify it passes (throttling already configured in Task 7)**

Run: `php bin/phpunit tests/User/Presentation/LoginThrottlingTest.php`
Expected: PASS. If it FAILS with tokens issued, throttling is misconfigured — fix `login_throttling` in `security.yaml` before proceeding.

- [ ] **Step 3: Update CLAUDE.md**

In `CLAUDE.md`: replace the "Project state" paragraph and add an "Authentication" subsection under Architecture:

```markdown
## Project state

Symfony 8.1 app (PHP >=8.4). Implemented so far: `Shared` CQRS kernel (command/query buses on Messenger) and the `User` context (JWT-cookie auth). The `Course` context is planned but not started.

- **Authentication** (`src/User/`): whole-app stateless auth — JWT (RS256, lexik) in HttpOnly cookie `AUTH_TOKEN` (15 min) + single-use rotating refresh token (gesdinet, DB table `refresh_tokens`) in cookie `REFRESH_TOKEN` (7 days). `SilentRefreshListener` (kernel.request, priority 16) rotates tokens before the firewall; reuse of a rotated refresh token revokes all the user's tokens. Registration goes through the command bus (`RegisterUser`); `User` is a classic ORM entity (deliberate exception from event sourcing). Login throttling: 5 attempts. Functional tests MUST use `https://localhost/...` URLs — auth cookies are `Secure`.
```

- [ ] **Step 4: Run the full suite one last time**

Run: `php bin/phpunit`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add tests/User/Presentation/LoginThrottlingTest.php CLAUDE.md
git commit -m "test(user): login throttling regression test; document auth architecture"
```

---

## Known risks (verify during execution)

1. **Bundle compatibility with Symfony 8.1**: `gesdinet/jwt-refresh-token-bundle` may lag behind Symfony 8. If `composer require` fails in Task 1, check for a compatible release/fork; fallback is a small in-house `RefreshToken` entity + generator (the rotator already owns all rotation logic, so only `issueFor` internals would change).
2. **Session availability for flashes**: auth is stateless, but flashes (registration success, login error) use the framework session. This is sessions-for-UX, not sessions-for-auth — per spec. If `framework.session` is disabled, enable it without affecting the stateless firewall.
3. **`RefreshTokenInterface::getUser()`** may not exist in the installed gesdinet version — fallback noted inline in Task 8.
4. **Access JWT stays valid ≤15 min after logout** — accepted trade-off (spec); a denylist is out of scope v1.

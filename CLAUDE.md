# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

Symfony 8.1 app (PHP >=8.4). Implemented so far: `Shared` CQRS kernel (command/query buses on Messenger) and the `User` context (JWT-cookie auth). The `Course` context is planned but not started.

- **Authentication** (`src/User/`): whole-app stateless auth — JWT (RS256, lexik) in HttpOnly cookie `AUTH_TOKEN` (15 min) + single-use rotating refresh token (gesdinet, DB table `refresh_tokens`) in cookie `REFRESH_TOKEN` (7 days). `SilentRefreshListener` (kernel.request, priority 16) rotates tokens before the firewall; reuse of a rotated refresh token revokes all the user's tokens. Registration goes through the command bus (`RegisterUser`); `User` is a classic ORM entity (deliberate exception from event sourcing). Login throttling: 5 attempts. Functional tests MUST use `https://localhost/...` URLs — auth cookies are `Secure`.

## Commands

Install PHP dependencies:
```
composer install
```

Start the local database (PostgreSQL) and mailer (Mailpit) via Docker Compose:
```
docker compose up -d
```
- Postgres is exposed on `5432`, Mailpit SMTP on `1025` and its web UI on `8025` (see `compose.override.yaml`).

Run the app (Symfony CLI, if installed):
```
symfony serve
```
or use the built-in PHP server against `public/index.php`.

Database (Doctrine):
```
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console make:migration          # generate a migration from entity changes
```

Tests (PHPUnit via the Symfony test harness):
```
php bin/phpunit
php bin/phpunit --filter testMethodName
php bin/phpunit tests/Path/To/SomeTest.php
```
- `APP_ENV=test` is forced by `phpunit.dist.xml`. Test config lives in `.env.test`.
- `failOnDeprecation`, `failOnNotice`, and `failOnWarning` are all enabled — deprecations/notices fail the suite, not just warn.
- Login throttling counters live in the `cache.rate_limiter` pool (not `cache.app`), which DAMA's per-test DB rollback does NOT reset. `LoginThrottlingTest` deliberately burns 5 failed attempts per run against a fixed 1-minute window; rerunning the suite (or just that test) within the same window carries the IP-based global counter (25/min, i.e. `5 * max_attempts`) over into the next run, spuriously throttling unrelated logins in `LoginTest`/`LogoutTest`/`SilentRefreshTest`. It self-heals once the window rolls over, but if you see logins unexpectedly redirecting to `/login` with no cookies set on a quick rerun, run `php bin/console cache:pool:clear cache.rate_limiter --env=test` before `php bin/phpunit`.

Frontend assets (AssetMapper — no Node/npm build step, no `package.json`):
```
php bin/console asset-map:compile
php bin/console importmap:require <package>
```
- JS is managed through `importmap.php` and Symfony's AssetMapper, not Webpack/Vite. Add new JS dependencies with `importmap:require`, not `npm install`.

Useful debug commands:
```
php bin/console debug:router
php bin/console debug:container
php bin/console debug:asset-map
```

## Architecture

- **Framework**: Symfony 8.1 with `symfony/runtime`; entry point is `public/index.php`, kernel is `src/Kernel.php` (standard `MicroKernelTrait`-free skeleton, config-driven via `config/bundles.php` and `config/packages/*.yaml`).
- **Service autowiring**: all classes under `src/` are auto-registered as services (`config/services.yaml`, `App\` resource with `autowire: true` / `autoconfigure: true`). No manual service wiring is needed for typical controllers/services/commands.
- **Database**: Doctrine ORM + Migrations against PostgreSQL 16 (`DATABASE_URL` in `.env`). Local Postgres runs via `compose.yaml`/`compose.override.yaml`; migrations live in `migrations/`.
- **Async messaging**: Symfony Messenger is installed with the Doctrine transport (`MESSENGER_TRANSPORT_DSN=doctrine://default`, see `config/packages/messenger.yaml`) — queued messages are stored in the app's own database, no external broker configured.
- **Mailer**: `symfony/mailer`, DSN set to `null://null` in `.env` (no-op) for base config; local dev mail is caught by Mailpit (see compose override) — override `MAILER_DSN` locally to route through it.
- **Frontend stack**: Symfony AssetMapper (no bundler) + Stimulus (`symfony/stimulus-bundle`) + Turbo (`symfony/ux-turbo`). JS entrypoint is `assets/app.js`; Stimulus controllers live in `assets/controllers/` and are auto-registered via `assets/controllers.json` and `assets/stimulus_bootstrap.js`. Mercure-based Turbo Streams are present but disabled by default in `assets/controllers.json`.
- **Security**: `symfony/security-bundle` is installed but `config/packages/security.yaml` has no firewalls/providers configured yet beyond framework defaults — auth needs to be designed when first needed.
- **Environment config**: `.env` holds committed defaults; `.env.dev` / `.env.test` layer environment-specific values; secrets must go in uncommitted `.env.local` / `.env.$APP_ENV.local` files, never in committed `.env*` files.

## Available Symfony skills

This environment has a large set of scoped skills for Symfony patterns (Doctrine relations/migrations/transactions, API Platform resources/filters/security, Symfony Messenger/Scheduler/Cache, Symfony UX Live/Twig/Turbo components, CQRS, voters, form validation, PHPUnit/Pest testing, value objects, ports & adapters, etc.). When a task matches one of these areas, load the relevant skill rather than improvising the pattern from scratch.

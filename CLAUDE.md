# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

This is a Symfony 8.1 skeleton application (PHP >=8.4) — currently unbuilt: `src/Controller`, `src/Entity`, and `src/Repository` only contain `.gitignore` placeholders, and `tests/` has no test cases yet. There is no `README.md`. Treat architectural decisions (entity design, controller structure, API shape) as open; there is no existing pattern in `src/` to follow yet, so check with the user or infer from the relevant Symfony skill (see below) before introducing one.

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

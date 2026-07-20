# Frontend Foundation + Auth Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the Symfony UX frontend architecture (Tailwind + anonymous Twig components) from `docs/frontend-architecture.md`, and deliver styled login + registration pages as the first slice.

**Architecture:** Tailwind (symfonycasts/tailwind-bundle, standalone CLI, no Node) with design tokens in `@theme`; a small UI kit of anonymous Twig components in `templates/components/` (the ONLY place, plus the form theme and `app.css`, that carries Tailwind classes); pages compose components and stay class-free. All PHP (controllers, authenticator) is untouched — the hand-written login form keeps its exact field/CSRF contract and is merely restyled through the `Field` component.

**Tech Stack:** Symfony 8.1, PHP 8.4, symfony/ux-twig-component, symfonycasts/tailwind-bundle (Tailwind v4), AssetMapper + Stimulus + Turbo (already present), PHPUnit 13.

## Global Constraints

- **Rule 1 (grep-enforced):** Tailwind `class="…"` may appear ONLY in `templates/components/**`, `templates/form/theme.html.twig`, `assets/styles/app.css`, and `templates/base.html.twig` (skeleton). `grep -rn 'class="' templates/user` MUST return nothing after Task 3. Passing a utility class to a component from a page also counts as a violation — use props (e.g. Button `wide`) instead.
- **Preserve auth contracts EXACTLY (no PHP changes):**
  - Login form: `method="post" action="{{ path('app_login') }}"`; visible fields named `email` and `password`; hidden `<input name="_csrf_token" data-controller="csrf-protection" value="{{ csrf_token('authenticate') }}">`; hidden `<input name="_target_path" value="{{ target_path }}">`; submit button text `Log in`.
  - Register form: Symfony form, block prefix `registration`, fields `email` + `plainPassword.first` / `plainPassword.second`; submit button text `Register`.
  - Nav: a `<nav>` element; when logged in shows `app.user.userIdentifier` and a logout `<form method="post" action="{{ path('app_logout') }}">` with hidden `<input name="_csrf_token" data-controller="csrf-protection" value="{{ csrf_token('logout') }}">` and a button text `Log out`; when anonymous shows `Log in` and `Register` links.
- **Components:** anonymous only (no PHP classes); `{% props %}` at top; forward extras via `{{ attributes.defaults({ class: … }) }}`; render with HTML `<twig:Name>` / `<twig:Sub:Name>` syntax; PascalCase; subfolder = namespace.
- **Tokens:** accent = indigo, success = emerald, error = red, warning = amber; shared radius `rounded-ui`. No arbitrary values (rule 7). zinc neutrals come from the Tailwind default palette.
- **Tests:** the full suite (28 tests) stays green at every task boundary. The ONLY permitted test change is the two `.flash-error` selector assertions in `tests/User/Presentation/LoginTest.php`, updated in Task 3.
- All new template files end in `.twig`; PHP files (none expected) would start `<?php declare(strict_types=1);`.

---

### Task 1: Foundation — Tailwind, tokens, package + config wiring

**Files:**
- Modify: `composer.json` / `composer.lock` / `config/bundles.php` / `symfony.lock` (via composer)
- Create: `config/packages/twig_component.yaml`
- Modify: `config/packages/twig.yaml`
- Overwrite: `assets/styles/app.css`
- Create: `templates/form/theme.html.twig` (stub; full theme in Task 2)
- Create/verify: `config/packages/tailwind.yaml` (recipe)

**Interfaces:**
- Consumes: nothing.
- Produces: `<twig:…>` rendering enabled; form theme `form/theme.html.twig` registered (file created in Task 2); `app.css` exposing `@theme` tokens (`accent`, `success`, `error`, `warning`, `--radius-ui`) and the `.field-label` / `.field-input` / `.field-error` component classes that Task 2's theme + `Field` both consume.

- [ ] **Step 1: Install the packages**

```bash
composer require symfony/ux-twig-component symfonycasts/tailwind-bundle
```

Expected: `Symfony\UX\TwigComponent\TwigComponentBundle` and `Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle` added to `config/bundles.php`; a `config/packages/twig_component.yaml` may be created by the recipe.

- [ ] **Step 2: Initialise Tailwind (downloads the standalone binary)**

```bash
php bin/console tailwind:init
```

Expected: downloads the Tailwind v4 standalone binary (to `var/tailwind/`), ensures `assets/styles/app.css` starts with `@import "tailwindcss";`, and creates `config/packages/tailwind.yaml` (input `assets/styles/app.css`).
If the binary cannot be downloaded in this environment, STOP and report BLOCKED with the exact error — do not hand-roll a Tailwind build.

- [ ] **Step 3: Write the design tokens + shared field classes**

Overwrite `assets/styles/app.css`:

```css
@import "tailwindcss";

@theme {
    /* Accent — indigo */
    --color-accent-50: #eef2ff;
    --color-accent-100: #e0e7ff;
    --color-accent-500: #6366f1;
    --color-accent-600: #4f46e5;
    --color-accent-700: #4338ca;

    /* Flash / status semantics */
    --color-success-50: #ecfdf5;
    --color-success-600: #059669;
    --color-success-700: #047857;
    --color-error-50: #fef2f2;
    --color-error-600: #dc2626;
    --color-error-700: #b91c1c;
    --color-warning-50: #fffbeb;
    --color-warning-600: #d97706;
    --color-warning-700: #b45309;

    /* Course status colors — draft = amber, published = emerald (used by Badge later) */
    --color-draft-600: #d97706;
    --color-published-600: #059669;

    /* Shared radius for cards / buttons / inputs */
    --radius-ui: 0.625rem;
}

/* Single source of truth for form-control appearance (rule 8):
   referenced by both templates/form/theme.html.twig and the Field component. */
@layer components {
    .field-label {
        @apply block text-sm font-medium text-zinc-700 mb-1;
    }

    .field-input {
        @apply block w-full rounded-ui border border-zinc-300 bg-white px-3 py-2 text-zinc-900
               shadow-sm outline-none placeholder:text-zinc-400
               focus:border-accent-600 focus:ring-2 focus:ring-accent-600/30;
    }

    .field-error {
        @apply mt-1 text-sm text-error-600;
    }
}
```

- [ ] **Step 4: Register the form theme + confirm the component dir**

`config/packages/twig.yaml` — add `form_themes` (keep the rest):

```yaml
twig:
    file_name_pattern: '*.twig'
    form_themes:
        - 'form/theme.html.twig'

when@test:
    twig:
        strict_variables: true
```

Ensure `config/packages/twig_component.yaml` contains (create if the recipe didn't):

```yaml
twig_component:
    anonymous_template_directory: 'components/'
    defaults: []
```

Create a MINIMAL form theme stub now so the just-registered `form_themes` entry resolves when `RegistrationTest` renders the (still-old) register form. Task 2 Step 6 replaces it with the full theme.

`templates/form/theme.html.twig`:

```twig
{% use 'form_div_layout.html.twig' %}
```

- [ ] **Step 5: Build + verify the container and the suite**

```bash
php bin/console tailwind:build
php bin/console lint:container
php bin/console cache:clear --env=test
php bin/phpunit
```

Expected: Tailwind build writes compiled CSS with no error; container lints clean; suite is **28 tests green** (1 pre-existing PHPUnit-internal deprecation is expected noise). The stub theme inherits Bootstrap-free default form rendering, so the register form still renders exactly as before — no test markup changes at this task.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock symfony.lock config/ assets/styles/app.css templates/form/theme.html.twig docs/frontend-architecture.md
git commit -m "feat(front): install ux-twig-component + tailwind, define design tokens"
```

---

### Task 2: UI kit — components + form theme

**Files:**
- Create: `templates/components/Button.html.twig`
- Create: `templates/components/Alert.html.twig`
- Create: `templates/components/Card.html.twig`
- Create: `templates/components/Field.html.twig`
- Create: `templates/components/Auth/Card.html.twig`
- Create: `templates/components/Layout/Nav.html.twig`
- Create: `templates/components/Layout/Flashes.html.twig`
- Replace: `templates/form/theme.html.twig` (the Task 1 stub → full theme)

**Interfaces:**
- Consumes: `.field-*` classes + `@theme` tokens (Task 1); routes `app_home`, `app_login`, `app_register`, `app_logout` (existing).
- Produces (component contracts consumed by Task 3):
  - `<twig:Button variant="primary|danger|ghost" href=? type="button|submit" wide=false>` + default slot.
  - `<twig:Alert type="success|error|warning|info">` + default slot; renders `role="alert"`.
  - `<twig:Card>` + default slot.
  - `<twig:Field label name type="text" value="" required=false autofocus=false autocomplete=? id=?>` — labeled raw input for hand-written forms.
  - `<twig:Auth:Card title footerText=? footerLinkLabel=? footerLinkHref=?>` + default slot.
  - `<twig:Layout:Nav />`, `<twig:Layout:Flashes />`.

- [ ] **Step 1: Button**

`templates/components/Button.html.twig`:

```twig
{% props variant = 'primary', href = null, type = 'button', wide = false %}

{% set styles = {
    primary: 'bg-accent-600 text-white hover:bg-accent-700 focus:ring-accent-600',
    danger:  'bg-error-600 text-white hover:bg-error-700 focus:ring-error-600',
    ghost:   'bg-transparent text-zinc-700 hover:bg-zinc-100 focus:ring-zinc-400',
} %}
{% set classes = 'inline-flex items-center justify-center gap-2 rounded-ui px-4 py-2 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none'
    ~ ' ' ~ styles[variant]
    ~ (wide ? ' w-full') %}

{# One {% block content %} only — Twig forbids two blocks of the same name in a
   template, so the tags are split around a single slot rather than one per branch. #}
{% if href %}<a href="{{ href }}" {{ attributes.defaults({ class: classes }) }}>{% else %}<button type="{{ type }}" {{ attributes.defaults({ class: classes }) }}>{% endif %}{% block content %}{% endblock %}{% if href %}</a>{% else %}</button>{% endif %}
```

- [ ] **Step 2: Alert, Card**

`templates/components/Alert.html.twig`:

```twig
{% props type = 'info' %}

{% set styles = {
    success: 'bg-success-50 text-success-700 border-success-600/20',
    error:   'bg-error-50 text-error-700 border-error-600/20',
    warning: 'bg-warning-50 text-warning-700 border-warning-600/20',
    info:    'bg-zinc-50 text-zinc-700 border-zinc-300',
} %}

<div role="alert" {{ attributes.defaults({ class: 'rounded-ui border px-4 py-3 text-sm ' ~ styles[type] }) }}>
    {% block content %}{% endblock %}
</div>
```

`templates/components/Card.html.twig`:

```twig
<div {{ attributes.defaults({ class: 'rounded-ui border border-zinc-200 bg-white p-6 shadow-sm' }) }}>
    {% block content %}{% endblock %}
</div>
```

- [ ] **Step 3: Field (raw labeled input)**

`templates/components/Field.html.twig`:

```twig
{% props label, name, type = 'text', value = '', required = false, autofocus = false, autocomplete = null, id = null %}
{% set fieldId = id ?? name %}

<div class="mb-4">
    <label for="{{ fieldId }}" class="field-label">{{ label }}</label>
    <input
        type="{{ type }}"
        id="{{ fieldId }}"
        name="{{ name }}"
        value="{{ value }}"
        class="field-input"
        {{ required ? 'required' }}
        {{ autofocus ? 'autofocus' }}
        {% if autocomplete %}autocomplete="{{ autocomplete }}"{% endif %}
    >
</div>
```

- [ ] **Step 4: Auth:Card**

`templates/components/Auth/Card.html.twig`:

```twig
{% props title, footerText = null, footerLinkLabel = null, footerLinkHref = null %}

<div class="mx-auto max-w-sm">
    {# Panel inlined (not <twig:Card>) so this component's `content` slot is not
       nested inside Card's `content` block — Twig forbids duplicate block names. #}
    <div class="rounded-ui border border-zinc-200 bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-xl font-semibold text-zinc-900">{{ title }}</h1>
        {% block content %}{% endblock %}
    </div>

    {% if footerText %}
        <p class="mt-4 text-center text-sm text-zinc-600">
            {{ footerText }}
            <a href="{{ footerLinkHref }}" class="font-medium text-accent-600 hover:text-accent-700">{{ footerLinkLabel }}</a>
        </p>
    {% endif %}
</div>
```

- [ ] **Step 5: Layout:Nav, Layout:Flashes**

`templates/components/Layout/Nav.html.twig`:

```twig
<nav class="border-b border-zinc-200 bg-white">
    <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
        <a href="{{ path('app_home') }}" class="text-sm font-semibold text-zinc-900">Ebooki</a>
        <div class="flex items-center gap-3 text-sm">
            {% if app.user %}
                <span class="text-zinc-600">{{ app.user.userIdentifier }}</span>
                <form method="post" action="{{ path('app_logout') }}">
                    <input type="hidden" name="_csrf_token" data-controller="csrf-protection" value="{{ csrf_token('logout') }}">
                    <twig:Button type="submit" variant="ghost">Log out</twig:Button>
                </form>
            {% else %}
                <twig:Button href="{{ path('app_login') }}" variant="ghost">Log in</twig:Button>
                <twig:Button href="{{ path('app_register') }}" variant="primary">Register</twig:Button>
            {% endif %}
        </div>
    </div>
</nav>
```

`templates/components/Layout/Flashes.html.twig`:

```twig
{% set typeMap = { success: 'success', error: 'error', warning: 'warning' } %}
{% for label, messages in app.flashes %}
    {% for message in messages %}
        <twig:Alert type="{{ typeMap[label] ?? 'info' }}" class="mb-3">{{ message }}</twig:Alert>
    {% endfor %}
{% endfor %}
```

- [ ] **Step 6: Form theme**

`templates/form/theme.html.twig`:

```twig
{% use 'form_div_layout.html.twig' %}

{% block form_row %}
    <div class="mb-4">
        {{ form_label(form, null, { label_attr: { class: 'field-label' } }) }}
        {{ form_widget(form, { attr: { class: 'field-input' } }) }}
        {{ form_errors(form) }}
    </div>
{% endblock %}

{% block form_errors %}
    {% if errors|length > 0 %}
        <ul class="mt-1 space-y-1">
            {% for error in errors %}
                <li class="field-error">{{ error.message }}</li>
            {% endfor %}
        </ul>
    {% endif %}
{% endblock %}
```

- [ ] **Step 7: Lint + suite (kit is not consumed yet — nothing should change)**

```bash
php bin/console lint:twig templates
php bin/console lint:container
php bin/console cache:clear --env=test
php bin/phpunit
```

Expected: twig lints clean (including `{% props %}` components), container clean, **28 tests green**. The pages/base still use the old markup, so behavior is unchanged.

- [ ] **Step 8: Commit**

```bash
git add templates/components templates/form
git commit -m "feat(front): UI kit (Button, Alert, Card, Field, Auth:Card, Layout:Nav/Flashes) + form theme"
```

---

### Task 3: Recompose base + auth pages; align tests; enforce rule 1

**Files:**
- Modify: `templates/base.html.twig`
- Replace: `templates/user/login.html.twig`
- Replace: `templates/user/register.html.twig`
- Modify: `tests/User/Presentation/LoginTest.php` (flash selector only)

**Interfaces:**
- Consumes: every component from Task 2; the auth contracts in Global Constraints.
- Produces: styled, class-free login + register pages; base composing `Layout:Nav` + `Layout:Flashes`.

- [ ] **Step 1: Update the two flash-selector assertions (RED first)**

In `tests/User/Presentation/LoginTest.php`, the generic-error flash now renders through `<twig:Alert type="error">` (which emits `role="alert"`), not a `.flash-error` element. Change both occurrences:

```php
// testFailedLoginShowsGenericError
self::assertResponseRedirects('/login');
$this->client->followRedirect();
self::assertSelectorTextContains('[role="alert"]', 'Invalid email or password.');
```

```php
// testUnknownEmailShowsSameGenericError
self::assertResponseRedirects('/login');
$this->client->followRedirect();
self::assertSelectorTextContains('[role="alert"]', 'Invalid email or password.');
```

Run them now to see them FAIL against the still-old template (proves the assertion is exercised):

```bash
php bin/phpunit --filter 'testFailedLoginShowsGenericError|testUnknownEmailShowsSameGenericError'
```

Expected: FAIL — the old template renders `.flash-error`, there is no `[role="alert"]` yet.

- [ ] **Step 2: Recompose the base skeleton**

Replace the `<nav>…</nav>` block and body wrapper in `templates/base.html.twig`. Keep `<head>` (title, favicon, stylesheets/importmap blocks, FrankenPHP hot-reload block) intact. New `<body>`:

```twig
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <twig:Layout:Nav />
        <main class="mx-auto max-w-5xl px-4 py-8">
            <twig:Layout:Flashes />
            {% block body %}{% endblock %}
        </main>
    </body>
```

Also set the default title to `Ebooki`: `{% block title %}Ebooki{% endblock %}`.

- [ ] **Step 3: Login page**

Replace `templates/user/login.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Log in{% endblock %}

{% block body %}
    <twig:Auth:Card
        title="Log in"
        footerText="No account yet?"
        footerLinkLabel="Register"
        footerLinkHref="{{ path('app_register') }}"
    >
        <form method="post" action="{{ path('app_login') }}">
            <input type="hidden" name="_csrf_token" data-controller="csrf-protection" value="{{ csrf_token('authenticate') }}">
            <input type="hidden" name="_target_path" value="{{ target_path }}">

            <twig:Field label="Email" name="email" type="email" autocomplete="email" required autofocus />
            <twig:Field label="Password" name="password" type="password" autocomplete="current-password" required />

            <twig:Button type="submit" variant="primary" wide>Log in</twig:Button>
        </form>
    </twig:Auth:Card>
{% endblock %}
```

- [ ] **Step 4: Register page**

Replace `templates/user/register.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Register{% endblock %}

{% block body %}
    <twig:Auth:Card
        title="Register"
        footerText="Already have an account?"
        footerLinkLabel="Log in"
        footerLinkHref="{{ path('app_login') }}"
    >
        {{ form_start(form) }}
            {{ form_row(form.email) }}
            {{ form_row(form.plainPassword.first) }}
            {{ form_row(form.plainPassword.second) }}
            <twig:Button type="submit" variant="primary" wide>Register</twig:Button>
        {{ form_end(form) }}
    </twig:Auth:Card>
{% endblock %}
```

- [ ] **Step 5: Enforce rule 1 + lint + full suite**

```bash
grep -rn 'class="' templates/user; echo "exit: $?"
php bin/console lint:twig templates
php bin/console cache:clear --env=test
php bin/phpunit
```

Expected: the grep prints nothing and reports `exit: 1` (no matches — rule 1 holds); twig lints clean; **28 tests green**, including the now-`[role="alert"]` login flash assertions, the register form selectors (`registration[email]`, duplicate-email error inside `<form>`), and the logout/nav tests.

- [ ] **Step 6: Optional visual build (skip on failure — not a gate)**

```bash
php bin/console tailwind:build
```

Expected: recompiles CSS. If the binary is unavailable here, this is fine — functional tests do not depend on compiled CSS; the user runs `symfony serve` + `tailwind:build --watch` locally for the visual check.

- [ ] **Step 7: Commit**

```bash
git add templates/base.html.twig templates/user tests/User/Presentation/LoginTest.php
git commit -m "feat(front): compose base + login/register from the UI kit; align flash selectors"
```

---

## Self-review checklist (run before final review)

- `grep -rn 'class="' templates/user` → empty (rule 1).
- Login form still carries `name="email"`, `name="password"`, `_csrf_token` (id `authenticate`) with `data-controller="csrf-protection"`, `_target_path`, button `Log in`.
- Register form still block prefix `registration`, fields `email` / `plainPassword.first|second`, button `Register`.
- Nav renders `<nav>`; logout form posts to `app_logout` with `data-controller="csrf-protection"` csrf input + `Log out` button; anonymous state shows `Log in` / `Register`.
- No PHP files changed; no LiveComponent installed; no Badge/PageHeader/EmptyState created (rule 4).
- Full suite 28 green; only the two LoginTest flash assertions changed.

## Known risks

1. **Tailwind binary download** (`tailwind:init` / `tailwind:build`) needs network. If unavailable in this environment, Task 1 Step 2 is BLOCKED — report it; the config can still be committed and the user builds locally. Functional tests do not require compiled CSS.
2. **Form theme resolved before it exists** (Task 1 → Task 2 ordering). Mitigation is in Task 1 Step 5 (stub the theme file if lint/suite complains).
3. **`{% props %}` + `strict_variables` in test env** — all components declare defaults, so undefined-variable errors should not occur; if one does on an optional prop, give it an explicit default in `{% props %}`.

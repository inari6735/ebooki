# Frontend Architecture (Symfony UX) — Design

**Date:** 2026-07-14 (adopted into the ebooki project 2026-07-21)
**Status:** Approved

> Adopted from a prior project. This file is the canonical statement of the
> clean-front contract. Adaptations for the ebooki repo are noted at the bottom.

## Goal

Give the app a clean, neat UI and — more importantly — a front-end architecture with
explicit rules, so templates stay ordered, reusable, and consistent as the project grows.

## Decisions

| Decision | Choice |
|---|---|
| Component system | symfony/ux-twig-component: anonymous Twig components (HTML syntax `<twig:...>`); PHP-class components only when logic is needed |
| Live Components | symfony/ux-live-component: **deferred in this repo** until the first real interactive need (course search/filter) — see adaptations |
| CSS | Tailwind via symfonycasts/tailwind-bundle (standalone CLI, no Node); design tokens in `@theme` in app.css |
| Component taxonomy | Two tiers only: shared UI kit in `templates/components/` + pages that compose components (no atomic design) |
| Aesthetic | Bookly Design System v1.0: Poppins; brand blue `#2048D2` accent; navy ink on light bluish neutrals; DS shadow/radius scale; cards with subtle shadows |
| Scope | Whole app: base layout, course pages, login/registration |

## Rules (the clean-front contract)

1. **Utilities only inside components.** Tailwind classes may appear only in
   `templates/components/`, `templates/form/theme.html.twig`, `assets/styles/app.css`
   (`@theme` / `@layer`), and the `base.html.twig` skeleton. Pages
   (`templates/user/`, `templates/course/`, …) use `<twig:Card>`, never
   `class="rounded-xl border ..."`. Detectable with a grep for `class="` in page dirs.
2. **Props are a contract.** A component declares its props at the top
   (`{% props variant = 'primary', href = null %}`) and never reaches into page context.
3. **No domain logic in Twig.** Templates may iterate and compare prop strings; they may
   not compute, filter collections, or know business rules.
4. **Components are extracted on demand, not up front.** Something becomes a component
   when (a) it is used a second time, or (b) it has a meaningful props contract.
5. **Stimulus = behavior, not content.** One controller = one DOM behavior, named after
   the behavior. Content is always server-rendered.
6. **Turbo-first.** Navigation and forms go through Turbo Drive; code must survive the
   absence of full page loads (document-level listeners, idempotent controllers — the
   lesson from the CSRF bug).
7. **Tokens over values.** Colors/spacing only via the Tailwind scale and `@theme` —
   `text-accent-600`, not `text-[#4f46e5]`. Arbitrary values (`w-[137px]`) are forbidden.
8. **Forms through the theme.** Field appearance is defined once (the `.field-*`
   component classes in `app.css`, referenced by `form/theme.html.twig` and the `Field`
   component). Pages call `form_row` / `<twig:Field>`. No per-page field styling.
9. **Accessibility by default.** Components carry semantics: `Alert` has `role="alert"`,
   `Field` binds label to input, focus rings come from tokens.
10. **Naming.** Components `PascalCase` (subfolder = namespace: `Layout:Nav`), Stimulus
    controllers `kebab-case`, props `camelCase`.

## Design tokens (@theme in app.css)

Tokens encode the **Bookly Design System v1.0** (`design/design_system/design_system.png`).
`assets/styles/app.css` is the single source of truth.

- Palette: Bookly **brand blue** accent (`--color-accent-600` = `#2048D2`, hover `700`
  `#183DB5`, pressed `800` `#12318F`, light `100` `#EDF3FE`, subtle `50` `#F5F8FF`). The
  Bookly ink/border/surface ramp (text primary `#06142F`, secondary `#46536D`, muted
  `#7C879D`, border `#DEE5F0` / strong `#C7D1E6`, background `#FBFCFE`) is mapped onto
  Tailwind's `zinc` keys, so `text-zinc-*` / `border-zinc-*` / `bg-zinc-*` render DS
  colors. Semantic tokens: `--color-canvas`, `--color-surface`, `--color-border`,
  `--color-rating` (`#F6C52E`). Shared radius `--radius-ui` (12px).
- Elevation: DS navy-tinted `--shadow-sm…2xl` (→ `shadow-sm…shadow-2xl`).
- Typography: **Poppins**, self-hosted woff2 (weights 400/500/600/700) in `assets/fonts/`,
  `@font-face` + `--font-sans` in app.css (no external requests). Scale per DS
  (H1 700 40/48, H2 600 32/40, H3 600 24/32, Body 400 16/24, Small/Button/Link 14/20).

## Rules of extraction / page mapping

| Page | Composition |
|---|---|
| `base.html.twig` | `Layout:Nav` + centered `<main>` with `Layout:Flashes` + block body |
| `user/login`, `user/register` | `Auth:Card` (narrow, centered) with fields (theme / `Field`) + `Button`, cross-link |
| `course/*` (future) | `PageHeader`, `Card`, `Badge`, `EmptyState` — built when the course context lands |

## Component library (pre-built from the Design System)

By explicit decision (supersedes rule 4 "extract on demand" for the shared kit), the
Design System's atoms are built up front in `templates/components/` so pages can be
composed from them:

- **Primitives:** `Button` (primary/secondary/outline/ghost/danger · md/lg · disabled),
  `Icon` (DS icon set, `<twig:Icon name="…">`), `Badge` (bestseller/brand/neutral),
  `Chip` (selectable/removable), `Avatar` (initials/image), `Card`, `Alert`.
- **Form kit** (`components/Form/`): `Input` (text/email/password/search, icon, error,
  disabled, revealable), `Textarea`, `Checkbox`, `Radio`, `Toggle` (CSS-only switch),
  `Select`.
- **Auth composition:** `Auth:Shell`, `Auth:BrandPanel` / `Auth:RegisterBrand`,
  `Auth:Illustration`, `Auth:LoginCard` / `Auth:RegisterCard`, `Auth:SocialButton`,
  `Auth:TextField`, `Auth:Divider`; layout `Layout:Nav` / `Layout:Flashes`.

**Living styleguide:** `GET /_kit` (dev-only, `Shared/Presentation/StyleguideController`)
renders `templates/kit/index.html.twig` — a catalog of every component/state. Use it to
verify the kit and as a visual reference when building pages.

## Out of scope (v1)

- Any Live Component usage; dark mode; webfonts; icon libraries (inline SVG only);
  restyling Symfony error pages / profiler.

## Adaptations for the ebooki repo

- **Template dirs:** this project renders `templates/user/login.html.twig` and
  `user/register.html.twig` (not `security/` + `registration/`).
- **Login form stays hand-written.** It is read directly by `FormLoginAuthenticator`
  (fields `email` / `password`, hidden `_csrf_token` with token id `authenticate`,
  `_target_path`, `data-controller="csrf-protection"`). It is styled through the `Field`
  component rather than Symfony Forms, so the authenticator/CSRF contract is untouched.
- **ux-live-component deferred** until a real consumer exists (course search/filter).
- **UI kit built on demand:** only `Button`, `Alert`, `Card`, `Field`, `Auth:Card`,
  `Layout:Nav`, `Layout:Flashes` + the form theme in this first slice.
- **Tests exist here** (unlike the origin project). Functional tests assert on markup;
  they are kept green, updated only where flash markup intentionally changed.

# Pending backend — frontend mocks awaiting implementation

Living index of UI that is **built on the frontend but not yet wired to any backend**.
Anything listed here renders and looks real, but does nothing (or points at `#`) until
the backend behind it is implemented.

> **Convention:** whenever a new frontend element is added as a visual stub (a button,
> field, link, or toggle with no working backend), add a row here in the same change.
> When the backend lands, remove the row (or move it to a short "Done" note).

Last updated: 2026-07-21

---

## Auth — Login (`templates/user/login.html.twig` → `templates/components/Auth/LoginCard.html.twig`)

| Element | Where | Current stub | Backend needed |
|---|---|---|---|
| **Login with Google** | `Auth:SocialButton provider="google"` | `<a href="#">`, no action | Google OAuth2 flow (redirect + callback, create/link user, issue the app's JWT cookies) |
| **Login with Apple** | `Auth:SocialButton provider="apple"` | `<a href="#">`, no action | Sign in with Apple flow (same as above) |
| **"Zapamiętaj mnie"** | checkbox `name="_remember_me"` (checked) | posted but ignored | firewall `remember_me` (or longer refresh-token TTL); currently the 7-day refresh token already keeps sessions alive |
| **"Nie pamiętasz hasła?"** | `forgotPath` (default `#`) | link to `#` | password-reset flow: request form → emailed token → reset form → update hash |
| **"Chcę najpierw przeglądać ofertę"** | `offerPath` (default `#`) | link to `#` | public catalog / offer page (part of the not-yet-started `Course`/catalog context) |

**Working for real (not mocks):** email + password login, CSRF, silent token refresh, logout.

---

## Auth — Register (`templates/user/register.html.twig` → `templates/components/Auth/RegisterCard.html.twig`)

| Element | Where | Current stub | Backend needed |
|---|---|---|---|
| **Register with Google** | `Auth:SocialButton provider="google"` | `<a href="#">`, no action | Google OAuth2 sign-up (create user, issue cookies) |
| **Register with Apple** | `Auth:SocialButton provider="apple"` | `<a href="#">`, no action | Sign in with Apple sign-up |
| **"Imię i nazwisko"** | input `name="fullName"` (top-level, NOT in the Symfony form) | posted but ignored — the `User` entity has no name field | add a name to `RegisterUser` + `User` (migration), then move this field into `RegistrationFormType` / bind it |
| **"Akceptuję regulamin i politykę prywatności"** | checkbox `name="terms"` (checked, top-level) | posted but ignored; links point at `#` | make acceptance required (validation), persist consent + timestamp; real terms/privacy pages |
| **"Chcę otrzymywać informacje o nowościach i promocjach"** | checkbox `name="newsletter"` (top-level) | posted but ignored | newsletter opt-in storage / mailing integration |

**Working for real (not mocks):** email + password + repeat-password registration (bound to
`RegistrationFormType` with correct Symfony field names + CSRF), inline validation errors
(duplicate email, password length/mismatch), redirect to login on success.

**Note on field naming:** the mocked fields above deliberately use **top-level** names
(`fullName`, `terms`, `newsletter`) — NOT inside the `registration[...]` namespace — because
the Symfony form rejects unknown fields in its own namespace. When wiring a field to the
backend, move its name into `registration[...]` and add it to `RegistrationFormType`.

---

## Cross-page / navigation

| Element | Where | Current stub | Backend needed |
|---|---|---|---|
| **"Powrót na stronę główną" / Bookly logo** | `Auth:Shell` → `app_home` (`/`) | route exists but renders a near-empty placeholder (`Shared/Presentation/HomeController` → bare `base.html.twig`) | real homepage / landing page |
| **Terms & Privacy pages** | linked from register (`termsPath`) | `#` | static/legal pages |

---

## Global chrome — Header (`templates/components/Layout/Header.html.twig`)

| Element | Current stub | Backend needed |
|---|---|---|
| **Nav: Kategorie (dropdown)** | `<a href="#">` + caret, no menu | categories taxonomy + listing pages; the dropdown panel |
| **Nav: Bestsellery / Najnowsze / Promocje** | `href="#"` | catalog listing pages with the respective sorting/filter |
| **Nav: Jak to działa? / Dla autorów** | `href="#"` | static/marketing pages |
| **Search icon** | `<a href="#">` | search page + query backend |
| **Mobile menu** | works (Stimulus `disclosure`) — visual nav only | same targets as above |

Real: logo → `app_home`, Zaloguj się → `app_login`, Załóż konto → `app_register`, logout form (functional, CSRF-protected).

## Global chrome — Footer (`templates/components/Layout/Footer.html.twig`)

| Element | Current stub | Backend needed |
|---|---|---|
| **Newsletter form** | `action="#"`, `name="newsletter_email"`, does nothing | subscription endpoint + mailing integration |
| **Social icons** (Facebook/Instagram/X/YouTube) | `href="#"` | real profile URLs |
| **Link columns** (Bookly, Dla autorów, Moje konto, Pomoc) | every link `href="#"` | the target pages (about, blog, terms, privacy, author guides, account, help, FAQ, support) |
| **Contact block** | static text (`kontakt@bookly.pl`, phone, hours) | replace with real contact details when known |

## Related backend backlog (not frontend mocks, but adjacent)

These are tracked in memory (`auth-follow-ups`) and are worth pairing with the above when
touching auth: refresh-token pruning job, `isValid()` rotated-vs-expired distinction,
`__Host-` cookie prefix, prod keypair rotation.

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

## Add-eBook wizard (`src/Ebook/Presentation/`, `templates/ebook/publish/`)

4-step session-backed Symfony multi-step form (`/wystaw-ebook/{step}`). **Anyone can
fill the whole wizard** (incl. uploading files anonymously); login/registration is
asked for only at the final "Opublikuj"/"Zapisz szkic" click, after which the publish
completes automatically (session flag `publish_ebook_pending`, `_target_path` back to
step 4 — preserved through register too).
**Now persists for real:** files are uploaded to a staging area as they are added
(`EbookStagingController` → Flysystem `ebook.storage`, `Media` status `pending`),
and at step 4 ("Opublikuj"/"Zapisz szkic") everything is committed by
`PublishEbookFromWizard` — `Ebook` + `EbookFile` created, blobs moved to their
permanent key, `Media` marked `ready`. Storage: local disk in dev, S3-ready.

Remaining follow-ups:

| Element | Current state | Still needed |
|---|---|---|
| **PHP upload limits** | eBook files upload in **8 MB chunks** (`init → chunk → finalize`), so no request is large: `php.ini` (dev) + `public/.user.ini` (FPM/prod) set `upload_max_filesize`/`post_max_size` to just **12 MB** | — (done); a request larger than 12 MB (i.e. a broken client) is refused by PHP as intended |
| **Storage provider** | local disk (`var/storage/ebooks`) via Flysystem | wire the S3 adapter for prod (config block already stubbed in `flysystem.yaml`) |
| **Async processing** | `Media` marked `ready` at commit, no scan | virus/format scan + checksum + thumbnails before `ready`; `MediaStatus::FAILED` path |
| **CSRF on wizard navigation** | staging endpoints are CSRF-checked (`ebook_upload`); the step-1 "Dalej" and step-4 finalize forms are plain POST | add stateless CSRF (`data-controller="csrf-protection"`) to those forms |
| **Staging GC** | `ebook:prune-staged` command deletes stale `pending` media **and** abandoned chunk directories (age from the UUIDv7 upload id) | schedule it (Messenger Scheduler / cron) |
| **Category / language** | categories seeded (`CategoryFixtures`, `doctrine:fixtures:load --append`) and driven by the DB in the form; committed eBook gets its `category_id` by slug. Language uses Symfony `LanguageType` (full list, Polish names) → stored as a code | — (done); optional: sub-categories UI |
| **Direct-to-storage upload** | chunked upload through the app (small requests, per-chunk retry, completeness-checked assembly) | optional prod upgrade: presigned PUT / S3 multipart (needs S3/MinIO) to keep bytes off the app entirely |
| **Viewing a published eBook** | detail page still renders a fixed mock (below) | read the persisted `Ebook` by slug |
| **"Zapłać ile chcesz" / donations** (step 3) | `payWhatYouWant` persisted on the eBook | voluntary-donation option on the detail page + payment handling |

## eBook detail page (`src/Ebook/Presentation/EbookDetailController.php`, `templates/ebook/show.html.twig`)

Product page at `/ebook/{slug}` (`app_ebook_show`). Reuses the shared header/footer.
There is **no catalog backend**, so the controller renders one fixed sample eBook —
`{slug}` is currently cosmetic. Components live in `templates/components/Ebook/Detail/`
(`Hero`, `PurchaseCard`, `PaymentMarks`) + the generic `Layout:Breadcrumb`.

| Element | Current stub | Backend needed |
|---|---|---|
| **Whole page data** (title, author, price, rating, detailed-info table, description) | hard-coded array in the controller | look the eBook up by `{slug}` from the catalog; 404 when missing |
| **"Kup teraz"** | visual button, no action | add-to-cart / checkout + payment flow |
| **"Dodaj do ulubionych"** | visual button, no action | wishlist tied to the logged-in user |
| **Rating (4,8 · 326 ocen)** | static | real reviews/ratings aggregate |
| **Payment marks** (`PaymentMarks.html.twig`) | self-drawn VISA/Mastercard/blik/Apple Pay placeholders (no real logos) | swap for the actual accepted-provider marks once a payment provider is integrated |
| **Cover image** | gradient placeholder (`Ebook:Cover`) — no cover store | serve the real uploaded cover |
| **Breadcrumb category link** | `href="#"` | category listing page |
| **Delivery section** (`Ebook:Detail:Delivery`) | native (Twig/Tailwind) illustration of buy → pay → email-download; the mini mockups (add-to-cart, payment methods, download email) are static | build the real checkout, payment and post-purchase email/download flow it depicts |

## Homepage — Hero (`templates/components/Home/Hero.html.twig`)

| Element | Current stub | Backend needed |
|---|---|---|
| **"Wystaw swój eBook"** (primary CTA) | `href="#"` | author eBook-upload flow (auth-gated) |
| **"Przeglądaj ofertę"** (outline CTA) | `href="#"` | public catalog / offer listing |

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

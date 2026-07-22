# Płatności — architektura (kontekst `Commerce`)

> Status: **plan / design** (przed implementacją). Wersja 1 — 2026-07-22.
> Prowider: **Przelewy24 (P24)**. Model: **platforma = sprzedawca (merchant of record)**.
> Cel nadrzędny: **pełna audytowalność każdej płatności + niepodważalna historia zdarzeń.**

---

## 0. Decyzje (ustalone)

| Decyzja | Wybór | Konsekwencja |
|---|---|---|
| Provider | **Przelewy24** (REST API v1) | BLIK, karty, przelewy, Apple/Google Pay; jedno konto merchant |
| Merchant of record | **Platforma** | Platforma sprzedaje czytelnikowi, rozlicza VAT/faktury, autorowi **wypłaca** udział |
| Rozliczenie autorów | **Akrual w księdze teraz, wypłaty później** | P24 nie potrzebuje trybu marketplace na MVP — zbieramy całość, saldo autora rośnie w księdze |
| Model kontekstu | **Event sourcing** (tylko `Commerce`) | Reszta appki (`Ebook`, `User`) zostaje klasycznym ORM — spójnie z założeniem projektu |

**Odpowiedź na pytanie „czy platforma-jako-sprzedawca wystarczy?": tak, w zupełności.**
Na MVP zbierasz wszystkie płatności na jedno konto P24, a wypłaty autorom to osobny,
późniejszy proces (patrz §12). Nie potrzebujesz produktu „marketplace/split" P24.

---

## 1. Dlaczego event sourcing akurat tutaj

Wymóg „chcę móc analizować każdą płatność i śledzić historię zdarzeń" to dokładnie definicja
event sourcingu: **stan = suma niezmiennych zdarzeń**. Zamiast trzymać tylko „aktualny stan
zamówienia", zapisujemy każdą zmianę jako zdarzenie (`OrderPlaced`, `PaymentConfirmed`, …) w
append-only **event store**. Z tego wynika za darmo:

- **pełny audyt** — każda zmiana ma timestamp, payload, correlation/causation id, aktora;
- **odtwarzalna historia** — możesz zreplay'ować dowolne zamówienie zdarzenie po zdarzeniu;
- **read-modele** (projekcje) do UI/adminki — przebudowywalne z eventów;
- brak „zgubionych" zmian stanu (nic nie nadpisujemy, tylko dokładamy).

Do tego dokładamy **księgę double-entry** (ruchy pieniędzy) i **surowe zdarzenia providera**
(payloady z P24) — trzy niezależne, niezmienne ślady, które można ze sobą uzgadniać
(reconciliation).

Uwaga: ES stosujemy **wyłącznie w `Commerce`**. `Ebook`/`User` pozostają klasycznym ORM.

---

## 2. Kontekst i układ modułu

Nowy bounded context `Commerce`, izolowany (jak `Ebook`/`User`). Odwołania do innych kontekstów
tylko przez ID (bez asocjacji ORM):

```
src/Commerce/
  Domain/
    Order/                Order (agregat ES), stany, zdarzenia
    Money/                (reużyj Shared\Money — grosze, Currency, RevenueSplit, CommissionCalculator)
    Payment/              PaymentGateway (port), PaymentMethod, ProviderTxRef
    Entitlement/          DownloadGrant (read-model/entitlement)
    Ledger/               LedgerEntry, Account (double-entry)
    EventStore/           EventStore (port), StoredEvent, AggregateRepository
  Application/
    Command/              PlaceOrder, InitiatePayment, ConfirmPayment, FailPayment, RefundOrder…
    Handler/              command handlery
    Reactor/              FulfillOrder (→ entitlement), PostToLedger, SendReceiptEmail
  Infrastructure/
    EventStore/           DoctrineEventStore (tabela commerce_events)
    Payment/              Przelewy24Gateway (adapter), P24SignatureVerifier, P24Client
    Projection/           OrdersProjection, PaymentsProjection, LedgerProjection, EntitlementProjection
  Presentation/
    CheckoutController          POST „Kup teraz" → OrderPlaced → P24 register → redirect
    Przelewy24StatusController  publiczny webhook (urlStatus) — podpis, kolejka
    PaymentReturnController     urlReturn (tylko UX)
    DownloadController          serwowanie pliku po ważnym entitlemencie
```

---

## 3. Model domenowy

### 3.1 Agregat `Order` (event-sourced)

Jedno zamówienie = zakup (na start jednopozycyjny; struktura gotowa na koszyk = wiele pozycji).

**Snapshot w momencie zakupu jest niezmienny** — cena, prowizja, stawka VAT, autor są
„zamrożone" w `OrderPlaced`. Nigdy nie przeliczamy ich później z katalogu (katalog może się
zmienić — historia finansowa nie może).

Zdarzenia (`Domain/Order/Event/`):

| Zdarzenie | Kiedy | Kluczowe pola |
|---|---|---|
| `OrderPlaced` | klik „Kup teraz" | orderId, buyerId, lines[{ebookId, sellerId, unitGross, commissionBps, vatRateBps}], totalGross, currency, buyerCountry, withdrawalConsent, placedAt |
| `PaymentInitiated` | po rejestracji transakcji w P24 | provider='p24', sessionId, p24Token, chosenMethod?, initiatedAt |
| `PaymentConfirmed` | po **weryfikacji** notyfikacji P24 | p24OrderId, paidAmount, method, confirmedAt |
| `PaymentFailed` | odrzucenie / błąd | reason, failedAt |
| `PaymentExpired` | brak płatności w oknie | expiredAt |
| `OrderFulfilled` | dostęp przyznany | entitlementIds[], fulfilledAt |
| `OrderRefunded` | zwrot | amount, reason, refundedAt |
| `ChargebackReceived` | reklamacja w banku | amount, receivedAt |

Maszyna stanów (projekcja z eventów):

```
placed ─▶ awaiting_payment ─▶ paid ─▶ fulfilled
              │                 
              ├─▶ failed        (PaymentFailed)
              └─▶ expired       (PaymentExpired)
   paid/fulfilled ─▶ refunded   (OrderRefunded)
   paid/fulfilled ─▶ charged_back (ChargebackReceived)
```

Reguły w agregacie (invarianty): nie można `ConfirmPayment` bez `PaymentInitiated`; podwójny
`PaymentConfirmed` = no-op (idempotencja na poziomie agregatu); nie fulfill bez paid; itd.

### 3.2 Kwoty i podział przychodu

Reużywamy `Shared\Domain\Money` (grosze/integer), `Currency`, oraz istniejący
`CommissionCalculator` / `RevenueSplit` (masz już strategię 90/10 w basis points).
`OrderPlaced` zapisuje **snapshot splitu** (per pozycja): `sellerShare`, `platformFee` — żeby
zmiana reguły prowizji w przyszłości nie przepisała historii.

### 3.3 Entitlement (dostęp do pliku)

`DownloadGrant` — read-model/uprawnienie tworzone przez reactor na `OrderFulfilled`:
`{ id, buyerId, ebookId, orderId, expiresAt(7 dni), maxDownloads?, downloadCount }`.
Samo pobranie idzie przez **podpisany, wygasający URL** (nie surowy publiczny link).
Serwowanie pliku: `DownloadController` sprawdza ważny entitlement + limity, strumieniuje z
`FileStorage` (bez odsłaniania ścieżki storage). (Kontrola „tylko published/owner" jak w
`EbookDetailController` już jest w projekcie.)

### 3.4 Księga (double-entry, append-only)

Osobny, niezmienny ślad **pieniędzy** — zapisywany przez reactor `PostToLedger` na zdarzenia
domenowe. Każdy zestaw wpisów jest zbilansowany (Σ debetów = Σ kredytów).

Przykład — `PaymentConfirmed` dla eBooka 29,90 zł (=2990 gr, prowizja 10%):

```
DR  psp_clearing               2990   (aktywo: środki u P24 do rozliczenia)
CR  seller_payable:{authorId}  2691   (zobowiązanie: należne autorowi, 90%)
CR  platform_income             299   (przychód platformy, 10%)
```

(VAT dołoży osobny `CR vat_payable` — patrz §11; snapshot stawki jest w `OrderPlaced`.)

Wypłata autora: `DR seller_payable 2691 / CR bank 2691`. Zwrot/chargeback: odwrócenie.
Tabela `ledger_entries`: `{ id, entry_group_id, account, direction(DR/CR), amount, currency, order_id?, occurred_at, ref }`. Nigdy nie edytowana — korekty to nowe wpisy.

---

## 4. Event store (ZAIMPLEMENTOWANY — generyczna cegiełka w `Shared`)

Własny, lekki event store na Doctrine DBAL, **generyczny i reużywalny** — mieszka w
`Shared`, nie w `Commerce`, żeby każdy przyszły kontekst ES używał go bez modyfikacji.
Mechanizm jest zamknięty na zmiany; dodanie nowego eventu/agregatu nie dotyka jądra.

Jądro (`src/Shared/…/EventSourcing/`):
- **Domain**: `DomainEvent` (interfejs: `occurredAt()` + `toPayload()`/`fromPayload()` — każdy
  event sam włada swoją serializacją, store pozostaje głupi), `AggregateRoot` (bufor eventów,
  `recordThat()`, `reconstituteFromHistory()`, wersjonowanie/optimistic locking, dispatch
  `apply{Event}` przez refleksję), `EventStore` (port), `EventBus` (port), `AggregateHistory`,
  `EventMetadata` + `EventMetadataProvider` (port), wyjątki `ConcurrencyConflict` /
  `AggregateNotFound` / `CorruptEventStream`.
- **Infrastructure**: `DoctrineEventStore` (adapter, tabela `event_store`), `EventSerializer`
  (klasa↔typ; stały typ = FQCN + mapa aliasów na wypadek przyszłych zmian nazw, bez ruszania
  mechanizmu), `MessengerEventBus`, `RequestEventMetadataProvider`,
  `EventSourcedAggregateRepository` (bazowe repo: load/persist+publish).

Tabela `event_store` (append-only, migracja `Version20260722100000`):

```
id BIGSERIAL PK           -- globalna kolejność / pozycja w strumieniu
aggregate_id UUID
aggregate_type VARCHAR(120)   -- stabilna nazwa strumienia, np. "commerce.order"
version INT                   -- sekwencja per agregat
event_type VARCHAR(255)       -- FQCN eventu
payload JSONB
metadata JSONB                -- correlation_id, actor_id, ip (rozszerzalne)
occurred_at TIMESTAMPTZ(6)    -- czas faktu (z eventu)
recorded_at TIMESTAMPTZ(6)    -- czas zapisu
UNIQUE(aggregate_id, version) -- optimistic concurrency
```

- **Zapis** `append(aggregateType, aggregateId, expectedVersion, events[])` — kolizja wersji →
  `ConcurrencyConflict`. Używa domyślnego połączenia DBAL, więc dołącza do transakcji otwartej
  przez `doctrine_transaction` na command busie → eventy i sync-projekcje commitują atomowo.
- **Odczyt** `load(aggregateId)` → `AggregateHistory` (eventy + wersja); agregat odtwarzany
  przez `reconstituteFromHistory()`.
- **Publikacja projekcji**: `EventBus` (`MessengerEventBus`) publikuje każdy zapisany event na
  dedykowanym busie **`messenger.bus.event`** (BEZ `doctrine_transaction`, żeby sync-handler
  wszedł w istniejącą transakcję).

### 4.1 Sync vs async projekcje — mechanizm

Delivery wybiera **sam event**, implementując marker transportu (istniejący wzorzec projektu):
`SyncTransport` → handler in-process, w tej samej transakcji (read-modele wymagające
natychmiastowej spójności); `AsyncTransport` → do workera (I/O: maile, P24 verify). Routing już
jest w `messenger.yaml`. Handler projekcji: `#[AsMessageHandler(bus: 'messenger.bus.event')]`,
idempotentny (`ON CONFLICT DO NOTHING`).

> **Ograniczenie do zapamiętania (żeby nie przerabiać później):** jeden event = jeden transport,
> więc **wszystkie** handlery danego eventu jadą tym samym transportem. Gdy event potrzebuje
> jednocześnie sync-projekcji i async-efektu, wzorzec jest: event jest `SyncTransport`, a
> sync-handler **dispatchuje osobną async-komendę** na I/O (dokładnie jak w appce: sync
> `RegisterUser` → async `SendEmail`). Nie robimy jednego eventu „sync+async".

### 4.2 Pierwszy klient: `Commerce/Order` (MVP „Kup teraz")

Zaimplementowany pionowy przekrój dowodzący cegiełki: `Order` (agregat ES) + event `OrderPlaced`
(snapshot ceny/splitu 90/10/VAT-ready/zgody) → komenda `PlaceOrder` (`Command`+`SyncTransport`,
kwoty liczone serwerowo) → `EventSourcedOrderRepository` → **sync** `OrdersProjection` budujący
read-model `commerce_orders` w tej samej transakcji. Pokryte testami: domena (given/when/then),
event store (round-trip + concurrency), pełne przejście komenda→event→projekcja.

- **Rebuild** projekcji z pełnego strumienia (komenda konsolowa) — do dodania przy kolejnych
  read-modelach (`payments`, `entitlements`, `ledger_entries`).

---

## 5. Integracja Przelewy24 (REST API v1)

> ⚠️ Dokładne pola/nagłówki potwierdzić z aktualną dokumentacją P24 przy implementacji —
> API bywa aktualizowane. Poniżej model referencyjny.

Bazowy URL: sandbox `https://sandbox.przelewy24.pl`, prod `https://secure.przelewy24.pl`.
Auth REST: **HTTP Basic** (`posId` : `API key`/klucz raportów). Kwoty w **groszach**.

Sekwencja:

1. **Rejestracja transakcji** — `POST /api/v1/transaction/register`
   body: `merchantId, posId, sessionId(=nasze orderId), amount, currency:'PLN', description,
   email, country:'PL', language:'pl', urlReturn, urlStatus, sign`
   → zwraca `token`.
2. **Redirect** kupującego na `{base}/trnRequest/{token}` — P24 hostuje wybór metody
   (BLIK/karta/…), obsługuje 3DS/SCA. (My nie dotykamy danych karty → PCI **SAQ-A**.)
3. **Notyfikacja** (`urlStatus`) — P24 robi `POST` JSON:
   `merchantId, posId, sessionId, amount, originAmount, currency, orderId(P24), methodId,
   statement, sign`. **Weryfikujemy podpis** na surowym body.
4. **Weryfikacja** — `POST /api/v1/transaction/verify`
   body: `merchantId, posId, sessionId, amount, currency, orderId, sign`.
   **Dopiero pozytywny verify = płatność potwierdzona** (notyfikacja sama w sobie nie
   wystarcza — musimy potwierdzić kwotę i zweryfikować).

**Podpis (`sign`)**: SHA-384 z JSON-a ze ściśle określonym zestawem pól + **CRC** (klucz z
panelu). Osobny zestaw dla register i dla notyfikacji/verify. Implementacja w
`P24SignatureVerifier` (pełne testy jednostkowe — to punkt bezpieczeństwa).

**Sekrety** (tylko `.env.local` / sejf, nigdy w repo):
`P24_MERCHANT_ID, P24_POS_ID, P24_CRC, P24_API_KEY, P24_SANDBOX=1`.

---

## 6. Pełny przepływ zakupu

```
Synchronously (żądanie kupującego):
  Buyer ── POST /kup/{ebook} ──▶ CheckoutController
     ├─ waliduje: eBook published, cena z Pricing (NIE z frontu), zgoda withdrawal
     ├─ Command PlaceOrder → Order emituje OrderPlaced (snapshot ceny/splitu/VAT)
     ├─ Przelewy24Gateway.register(order) → token ; Order: PaymentInitiated
     └─ 303 redirect ▶ P24 (trnRequest/{token})

  Buyer płaci na P24 (BLIK/karta) ; P24 ── redirect ──▶ /platnosc/powrot  (tylko UX)

Asynchronously (źródło prawdy):
  P24 ── POST /webhook/p24 (urlStatus) ──▶ Przelewy24StatusController
     ├─ czyta RAW body, weryfikuje sign
     ├─ zapisuje surową notyfikację (p24_notifications) + dedupe
     ├─ dispatch Messenger: ConfirmPaymentFromP24(sessionId)
     └─ zwraca 200 szybko

  Worker (Messenger):
     ├─ P24.verify(...) — potwierdza kwotę u P24
     ├─ Command ConfirmPayment → Order: PaymentConfirmed
     ├─ Reactor FulfillOrder → OrderFulfilled + DownloadGrant(y)
     ├─ Reactor PostToLedger → wpisy księgowe (autor 90% / platforma 10% / VAT)
     └─ Reactor SendReceipt → mail z linkiem do pobrania
```

Kluczowe: **dostęp do pliku dajemy dopiero po `PaymentConfirmed` (po verify), nie na powrocie.**
`urlReturn` pokazuje tylko „dziękujemy, przetwarzamy" i odpytuje o status.

---

## 7. Audytowalność i wgląd (główny wymóg)

Trzy niezależne, niezmienne ślady + widoki:

1. **Event store** (`event_store`) — pełna historia każdego zamówienia; adminka „timeline"
   renderuje zdarzenia po kolei (kto, kiedy, co, correlation id).
2. **Surowe zdarzenia providera** (`p24_notifications`) — dokładny payload z P24 + wynik
   weryfikacji podpisu + status przetworzenia (do reconciliation i debugowania).
3. **Księga** (`ledger_entries`) — każdy ruch pieniędzy, zbilansowany.

Adminka (`Presentation`, gated `ROLE_ADMIN` — do dodania): lista płatności z filtrami/statusem,
widok pojedynczego zamówienia = timeline eventów + notyfikacje P24 + wpisy księgi + akcje
(zwrot). Reconciliation: cron porównujący nasze `paid` z raportem P24.

---

## 8. Reguły bezpieczeństwa i poprawności (twarde)

- **Webhook = źródło prawdy.** Fulfillment tylko po zweryfikowanym `PaymentConfirmed`.
- **Podpis na surowym body.** `Przelewy24StatusController`: publiczny, **wyłączony z CSRF i z
  firewalla auth**, weryfikuje `sign`. Odrzuć, jeśli podpis zły.
- **Idempotencja** na 3 poziomach: dedupe notyfikacji (`p24_notifications` po sessionId+status),
  optimistic concurrency w event store, oraz no-op przy powtórnym `PaymentConfirmed` w agregacie.
- **Kwoty po stronie serwera** z `Pricing` (dla PWYW walidacja wybranej kwoty). Front nigdy nie
  dyktuje ceny. Przy verify porównujemy kwotę z P24 z naszą — rozjazd = alarm, nie fulfill.
- **Grosze (integer)** wszędzie (`Money`), także w komunikacji z P24.
- **Sekrety** poza repo; osobne klucze sandbox/prod.
- **Messenger**: webhook odpowiada 200 szybko; ciężka robota (verify+fulfill) w workerze z retry.

---

## 9. Scenariusze brzegowe (obsłużyć jawnie)

| Sytuacja | Obsługa |
|---|---|
| Klient porzuca płatność | brak notyfikacji → po TTL `PaymentExpired` (scheduler); brak fulfillmentu |
| Notyfikacja wielokrotna | dedupe + idempotentny agregat |
| Kwota P24 ≠ nasza | nie fulfill; alarm; ręczna analiza |
| Zwrot | `RefundOrder` → P24 refund API → `OrderRefunded` → odwrócenie księgi; polityka dostępu do pliku (zwykle brak „odbierania") |
| Chargeback | `ChargebackReceived` → korekta księgi (może zejść z salda autora) |
| Podwójny zakup tego samego eBooka przez tego kupującego | reguła: już ma entitlement → blokuj/informuj |
| Webhook przyszedł zanim zapisaliśmy Order | retry Messenger; Order zawsze zapisany synchronicznie przed redirectem |

---

## 10. Read-modele (tabele projekcji, przebudowywalne)

```
orders          {id, buyer_id, status, total_gross, currency, placed_at, paid_at, fulfilled_at}
order_lines     {order_id, ebook_id, seller_id, unit_gross, commission_bps, seller_share, platform_fee, vat_rate_bps}
payments        {order_id, provider, session_id, p24_order_id, method, amount, status, confirmed_at}
entitlements    {id, buyer_id, ebook_id, order_id, expires_at, max_downloads, download_count}
ledger_entries  {id, entry_group_id, account, direction, amount, currency, order_id, occurred_at}
p24_notifications {id, session_id, p24_order_id, raw_payload, signature_valid, status, received_at}
```

---

## 11. Podatki i prawo (dane zbierane teraz, rozliczenie później)

Platforma = sprzedawca, więc:
- **VAT OSS** (usługi cyfrowe, stawka wg kraju kupującego; e-book w PL 5%). `OrderPlaced`
  zapisuje snapshot: `buyerCountry`, `vatRateBps`, rozbicie netto/VAT. Faktyczne remitowanie
  i faktury = osobna faza, ale **dane trzymamy od początku**.
- **Prawo odstąpienia (14 dni)**: dla treści cyfrowej z natychmiastowym dostępem —
  **checkbox zgody** przy zakupie (utrata prawa odstąpienia); flaga `withdrawalConsent` w
  `OrderPlaced`. Bez zgody nie inicjujemy płatności / nie dajemy natychmiastowego dostępu.
- **Faktura/paragon** dla kupującego — faza późniejsza; dane są w snapshot.

---

## 12. Wypłaty autorom (faza późniejsza)

Na MVP: saldo autora **narasta w księdze** (`seller_payable:{authorId}`) — w pełni audytowalne.
Realna wypłata = osobny proces: dane rozliczeniowe/KYC autora + przelew (np. P24 „wypłaty"/mass
payments albo przelew bankowy), księgowany jako `DR seller_payable / CR bank`. Zaprojektowane
tak, że dołączenie wypłat nie rusza modelu zakupu.

---

## 13. Testy

- **Domena `Order`** (czysty ES): styl *given(events) → when(command) → then(events)* — bez I/O,
  szybkie, pełne pokrycie invariantów.
- **`P24SignatureVerifier`**: wektory podpisów (poprawny/niepoprawny) — krytyczne.
- **Integracja**: symulowana notyfikacja `urlStatus` (podpisana) → sprawdzenie projekcji
  (order paid, entitlement, księga zbilansowana); dedupe (podwójna notyfikacja).
- **Sandbox P24** + BLIK/karta testowa; lokalnie tunel na `urlStatus` (ngrok/lokalny forward).
- Reużyj wzorca z projektu: testy funkcjonalne z `https://localhost/...`.

---

## 14. Plan wdrożenia (przyrostowo)

1. ✅ **Event store + kontekst `Commerce`** (fundament) — ZROBIONE: generyczny event-sourcing
   kernel w `Shared`, `event_store`, event bus, `Order`+`OrderPlaced`, `PlaceOrder`, sync
   `OrdersProjection`→`commerce_orders`, testy (domena + store + e2e). Bez P24.
2. ✅ **Checkout + P24 (sandbox), platforma jako sprzedawca** — ZROBIONE: `PaymentGateway` port +
   VO + `Przelewy24Gateway` (HttpClient, Basic auth, `P24Signer` SHA-384), `P24NotificationVerifier`,
   `CheckoutController` (`POST /kup/{slug}`, cena z Pricing, zgoda, CSRF `checkout`, redirect do
   P24 przez `StartCheckout`), `Przelewy24StatusController` (`POST /platnosc/przelewy24/status` —
   raw body, podpis, `p24_notifications` z dedupe, async dispatch, 200), `PaymentReturnController`
   (UX), async `ConfirmPaymentFromProvider` (verify + `PaymentConfirmed`), events
   `PaymentInitiated`/`PaymentConfirmed`/`PaymentFailed`, projekcje `commerce_orders`/`commerce_payments`,
   migracja `Version20260722140000`. Testy: domena (payment lifecycle), `P24Signer`, gateway
   (MockHttpClient), confirm handler, checkout e2e, webhook. **P24 za portem → testy na fake'u.**
3. ✅ **Fulfillment + księga** — ZROBIONE: `Order::fulfill()` + event `OrderFulfilled` (confirm+fulfill
   w jednym zapisie — towar cyfrowy dostępny od razu), `EntitlementProjection`→`commerce_entitlements`
   (własność trwała, UNIQUE buyer+ebook), `LedgerProjection`→`commerce_ledger_entries` (double-entry na
   `PaymentConfirmed`: DR psp_clearing / CR seller_payable{sellerId} / CR platform_income, zbilansowane,
   idempotentne przez `reference=confirm:{orderId}`), migracja `Version20260722170000`. (Mail z linkiem —
   odłożony.)
4. ✅ **Bezpieczne pobieranie** — ZROBIONE: `DownloadController` `GET /pobierz/{ebookId}` (ROLE_USER,
   sprawdza `Entitlements::owns` → brak = 404 nieodróżnialne, streaming z `FileStorage`, ścieżka storage
   nieujawniana). Link „Pobierz eBook" na stronie powrotu po fulfillmencie. Testy: fulfill (domena),
   confirm→entitlement→zbilansowana księga, download (entitled/obcy/anonim). (Podpisany-wygasający link —
   ewentualne wzmocnienie później; auth+entitlement już wystarcza.)
5. **Adminka/wgląd**: timeline eventów, lista płatności, reconciliation; zwroty.
6. **Prawo/VAT**: checkbox zgody + snapshot VAT (dane), potem faktury/OSS.
7. **Wypłaty autorom** (KYC + transfery).

---

## 15. Decyzje (rozstrzygnięte)

- **Event store** — ✅ własny, lekki, na Doctrine (generyczna cegiełka w `Shared`).
- **Projekcje** — ✅ per-event przez marker `SyncTransport`/`AsyncTransport`; MVP `commerce_orders`
  jest sync (spójne w transakcji). Ograniczenie „jeden event = jeden transport" → §4.1.
- **Zakup** — ✅ MVP: pojedynczy „Kup teraz" (jedna pozycja; model rozszerzalny na koszyk).
- **Następny krok** — faza 2: checkout + integracja Przelewy24 (sandbox).

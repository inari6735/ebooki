# Upsun — ściąga: ciekawe i przydatne komendy/funkcje

Wybór rzeczy, które realnie się przydają przy tej apce (Symfony, event sourcing +
płatności) i przy pojedynczym deweloperze pilnującym kosztów. Uzupełnienie do
`docs/deploy-upsun.md` (samego deployu/konfiguracji).

> Eksploracja: `upsun list` (wszystkie komendy), `upsun <komenda> --help` (flagi),
> `upsun web` (Console: metryki, logi, wykresy zużycia zasobów).

---

## 🥇 Środowiska = klony produkcji z danymi (killer feature)

Każdy branch gita może stać się pełnym, izolowanym środowiskiem — z **kopią bazy i
plików** z rodzica. Testujesz na prawdziwych danych, potem mergujesz.

```bash
upsun environment:branch test-platnosci   # env = kopia prod (baza + pliki var/storage)
# ...ryzykowna migracja, refactor event-store'a, test flow płatności...
upsun environment:merge                    # scal z powrotem
upsun environment:synchronize              # odśwież dane z rodzica
upsun environment:delete test-platnosci
```

**Po co:** zanim puścisz groźną migrację / zmianę w projekcjach na prod — sprawdzasz
na kopii danych, zero ryzyka na żywej instancji.

## 💸 Pauzowanie środowisk (największa oszczędność poza rozmiarem kontenerów)

Instancja testowa dla jednej osoby nie musi palić compute, gdy nie testujesz:

```bash
upsun environment:pause     # zatrzymuje kontenery (nie płacisz za CPU/RAM), dane zostają
upsun environment:resume    # wznowienie w kilkadziesiąt sekund
```

## 🔌 Dostęp do danych i usług (tunel, SQL, mounty)

```bash
upsun sql "SELECT id,status,total_amount FROM commerce_orders ORDER BY placed_at DESC LIMIT 20"
upsun db:dump                                 # zrzut całej bazy na dysk lokalny
upsun tunnel:open                             # tunele SSH do usług → lokalne psql/DBeaver do zdalnej bazy
upsun mount:download --mount var/storage --target ./backup-plikow   # ściągnij wgrane eBooki
upsun mount:upload   --mount var/storage --source ./pliki           # wgraj pliki na mount
```

Świetne do podglądania płatności / księgi / `event_store` bez wchodzenia w adminkę.

## 🛠️ Ops i debugowanie

```bash
upsun ssh                                            # wejście do kontenera
upsun ssh -- php bin/console messenger:failed:show   # nieudane wiadomości z kolejki
upsun ssh -- php bin/console user:promote-admin ja@mail
upsun redeploy                                       # ponowny deploy BEZ zmiany kodu (re-run hooka deploy)
upsun log app --lines 200                            # logi aplikacji
upsun activity:list                                  # audyt: kto/kiedy pushował, deployował, zmieniał zmienne
upsun activity:log                                   # log ostatniej operacji (build+deploy, output migracji)
```

`upsun redeploy` jest złoty po zmianie sekretu (żeby deploy hook się przeliczył).

## 💾 Backupy (automatyczne + na żądanie)

```bash
upsun backup:create
upsun backup:list
upsun backup:restore <id>    # przywraca środowisko do snapshotu (baza + pliki + config spójnie)
```

## 🧩 Funkcje „w configu", z których warto skorzystać

- **`crons:`** — zadania cykliczne (np. `ebook:prune-staged`; albo tańszy konsument
  Messengera cronem zamiast workera — patrz `docs/deploy-upsun.md`).
- **Source operations** — komenda uruchamiana w chmurze, która **commituje z powrotem
  do repo** (np. cotygodniowy `composer update` na osobnym branchu do review):
  `upsun source-operation:run`.
- **Integracja z GitHubem/GitLabem** — każdy PR dostaje automatycznie własne
  środowisko preview (link w PR-ze).
- **Wiele usług w jednym repo** — Redis / OpenSearch / RabbitMQ jako kolejne
  `services:` bez zmiany infrastruktury.
- **`web.commands.start`** — własny proces zamiast domyślnego php-fpm.

## ⚡ Profilowanie (Blackfire)

Wbudowana integracja z Blackfire — profilujesz realne żądania (gdzie wolno, ile
pamięci, ile zapytań do bazy). Dla apki z event-sourcingiem i projekcjami bardzo
pouczające.

---

## Top 3 na już (w tej sytuacji)

1. **`environment:pause`** — oszczędność, gdy nie testujesz.
2. **`environment:branch`** — testy na kopii prod przed groźnymi zmianami.
3. **`upsun sql` / `tunnel:open`** — podglądanie danych płatności bez klikania po admince.

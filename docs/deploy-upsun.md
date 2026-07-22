# Deploy na Upsun

Konfiguracja topologii jest w `.upsun/config.yaml` (jedna aplikacja PHP: web + worker
Messengera, usługa PostgreSQL). Poniżej kroki, których **nie da się** trzymać w repo
(sekrety, basic auth, przydział zasobów).

## 1. Sekrety (zmienne środowiskowe)

Ustaw jako **sensitive** zmienne środowiskowe (widoczne dla aplikacji jako env):

```bash
upsun variable:create env:APP_SECRET     --value "$(openssl rand -hex 32)" --sensitive true
upsun variable:create env:JWT_PASSPHRASE --value "$(openssl rand -hex 32)" --sensitive true

# Płatności (sandbox Przelewy24) — potrzebne do testów zakupu:
upsun variable:create env:P24_MERCHANT_ID --value "<id>"
upsun variable:create env:P24_POS_ID      --value "<id>"
upsun variable:create env:P24_CRC         --value "<crc>"  --sensitive true
upsun variable:create env:P24_API_KEY     --value "<key>"  --sensitive true
# P24_SANDBOX domyślnie 1 (z .env) — zostaw dla środowiska testowego.
```

- `DATABASE_URL` **ustawia się automatycznie** z relacji `database` (mapuje konfigurator Symfony).
- Klucze JWT są generowane w hooku `build` (regenerowane przy każdym deployu — po deployu
  trzeba się przelogować; dla instancji testowej OK).
- `MAILER_DSN` domyślnie `null://null` (brak wysyłki) — podmień, jeśli chcesz realne maile.

## 2. HTTP Basic Auth (zabezpieczenie całej instancji)

Cała instancja ma być za basic authem — włącz to na poziomie środowiska (nie w aplikacji):

```bash
upsun environment:http-access --access "allow:*" --auth "twoj_login:twoje_haslo"
```

lub w Console: **Settings → Environment → HTTP access control**. Dzięki temu żaden request
nie dojdzie do aplikacji bez autoryzacji.

## 3. Zasoby (minimalizacja kosztów)

Topologię definiuje config, ale **CPU/RAM/dysk przydzielasz osobno** (`upsun resources:set`,
najłatwiej interaktywnie). Dla instancji dla jednej osoby celuj w minimum:

| Kontener        | CPU   | RAM (profil) | Uwaga |
|-----------------|-------|--------------|-------|
| `app` (web)     | ~0.5  | najmniejszy  | PHP-FPM + OPcache/preload |
| `messenger`     | ~0.1  | najmniejszy  | osobny, mały kontener workera |
| `database`      | ~0.5  | najmniejszy  | Postgres |

Dysk: `database` ~1 GB, mount storage (logi + pliki eBooków) ~1–2 GB. Zaczynaj mało i
zwiększaj tylko jeśli coś realnie zabraknie.

**Chcesz ciąć jeszcze bardziej?** Worker to osobny (płatny) kontener. Możesz go usunąć z
configu i zamiast tego konsumować kolejkę cronem — jeden kontener mniej:

```yaml
    # zamiast bloku `workers:`
    crons:
      messenger:
        spec: '*/1 * * * *'
        commands:
          start: php bin/console messenger:consume async --time-limit=55 --limit=50
```

(Potwierdzenie płatności z webhooka P24 przyjdzie wtedy do ~1 min później — dla testów bez znaczenia.)

## 4. Pierwszy deploy

```bash
upsun project:set-remote <project-id>   # jeśli jeszcze nie powiązany
git push upsun main                      # lub `upsun push`
```

Hook `deploy` sam odpala migracje (`doctrine:migrations:migrate`) i tworzy tabelę transportu
Messengera (`messenger:setup-transports`). Po pierwszym deployu nadaj sobie admina:

```bash
upsun ssh -- php bin/console user:promote-admin twoj@email
```

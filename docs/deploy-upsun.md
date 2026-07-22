# Deploy na Upsun

Konfiguracja topologii jest w `.upsun/config.yaml` (jedna aplikacja PHP: web + worker
Messengera, usługa PostgreSQL). Poniżej kroki, których **nie da się** trzymać w repo
(sekrety, basic auth, przydział zasobów).

## 1. Sekrety (zmienne środowiskowe)

Ustaw jako **sensitive** zmienne środowiskowe (widoczne dla aplikacji jako env):

```bash
upsun variable:create env:APP_SECRET     --value "$(openssl rand -hex 32)" --sensitive true
upsun variable:create env:JWT_PASSPHRASE --value "$(openssl rand -hex 32)" --sensitive true

# Płatności — AKTYWNY provider to PayU (sandbox). Potrzebne do testów zakupu:
upsun variable:create env:PAYU_POS_ID        --value "<pos_id>"
upsun variable:create env:PAYU_CLIENT_ID     --value "<client_id>"   # zwykle = pos_id
upsun variable:create env:PAYU_CLIENT_SECRET --value "<secret>"      --sensitive true
upsun variable:create env:PAYU_SECOND_KEY    --value "<second_key>"  --sensitive true
# PAYU_SANDBOX domyślnie 1 (z .env) — zostaw dla środowiska testowego.
# (Przelewy24 pozostaje w kodzie jako alternatywa; przełączenie providera = jeden
#  alias `PaymentGateway` w config/services.yaml. P24_* trzeba ustawić tylko gdy wrócisz na P24.)
```

- `DATABASE_URL` **ustawia się automatycznie** z relacji `database` (mapuje konfigurator Symfony).
- Klucze JWT są generowane **w hooku `deploy`** (ma dostęp do runtime'owego `JWT_PASSPHRASE`,
  także `--sensitive`) i zapisywane na trwały mount `var/jwt` — generują się **raz** i przeżywają
  kolejne deploye (nie wylogowuje przy każdym pushu). **Rotacja passphrase:** ustaw nowy
  `JWT_PASSPHRASE`, usuń stare klucze i przegeneruj:
  ```bash
  upsun ssh -- 'rm -f var/jwt/*.pem && php bin/console lexik:jwt:generate-keypair --no-interaction'
  ```
  ⚠️ Klucze MUSZĄ powstać już z docelowym `JWT_PASSPHRASE` — ustaw tę zmienną **przed** pierwszym
  deployem. (Generowanie w `build` nie działa dla zmiennych `--sensitive`: są niewidoczne w
  buildzie → klucz szyfrowany fallbackiem z `.env`, a runtime odszyfrowuje prawdziwym →
  „bad decrypt".)
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

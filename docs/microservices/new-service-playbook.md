# Dodawanie nowego mikroserwisu (Go + gRPC) — ściągawka

Powtarzalny przepis na dołożenie do projektu mikroserwisu w Go, gadającego z
Symfony przez gRPC. Wzorcem jest `services/media` — jeśli coś jest niejasne,
zajrzyj tam, każdy krok ma tam działający odpowiednik.

Zasady, które obowiązują w całym przepisie:

- **Monorepo.** Serwis żyje w `services/<nazwa>/`, kontrakt `.proto` w `proto/`
  (ponad oboma stronami — to wspólne źródło prawdy).
- **Dwie płaszczyzny.** *Control plane* (operacje, małe payloady) = gRPC
  Symfony→Go. *Data plane* (strumień bajtów do przeglądarki) = podpisane URL-e po
  HTTP prosto z Go, żeby PHP-FPM nie streamował plików.
- **Kierunek zależności w Go:** `transport → core ← infra`. Rdzeń nie wie o gRPC
  ani o dysku. Interfejsy definiuje **konsument**, trzymaj je małe.
- **Symfony pozostaje klientem**, Go serwerem — to jedyna konfiguracja gRPC dobrze
  wspierana w PHP.

---

## 0. Toolchain (raz na maszynę)

```bash
# generatory kodu (trafiają do ~/go/bin — dodaj to do PATH)
go install google.golang.org/protobuf/cmd/protoc-gen-go@latest
go install google.golang.org/grpc/cmd/protoc-gen-go-grpc@latest
go install github.com/bufbuild/buf/cmd/buf@latest
export PATH="$PATH:$(go env GOPATH)/bin"
```

> **Gotcha tego środowiska (IPv6 bez trasy).** `go`/`buf` nie robią fallbacku na
> IPv4 → „network is unreachable". Obejście: mały proxy CONNECT wymuszający IPv4
> (`scratchpad/ipv4proxy.py`) i `export HTTPS_PROXY=http://127.0.0.1:8899
> HTTP_PROXY=http://127.0.0.1:8899` dla poleceń `go`, `buf`, `composer`, `curl`.
> `curl -4` działa bez tego; zwykłe `go` nie.

---

## 1. Szkielet serwisu Go

```
services/<svc>/
├── go.mod                       # module github.com/bookly/<svc>
├── cmd/<svc>/
│   ├── main.go                  # bootstrap: config → wiring → start → shutdown
│   └── config.go                # odczyt ENV
├── internal/
│   ├── <domain>/                # RDZEŃ: czyste typy Go, zero gRPC/IO
│   │   ├── service.go           # Service: logika, zależy od interfejsów
│   │   ├── <port>.go            # interfejs portu (definiowany TU) + błędy sentinel
│   │   └── service_test.go      # testy z atrapą w pamięci
│   ├── <adapter>/               # INFRA: implementacja portu (dysk, S3, …)
│   └── grpcserver/              # TRANSPORT: protobuf ⇆ rdzeń
└── gen/<svc>/v1/                # WYGENEROWANE Go (commitowane)
```

```bash
mkdir -p services/<svc> && cd services/<svc>
go mod init github.com/bookly/<svc>
```

Kolejność pisania — **od środka na zewnątrz** (rdzeń działa i jest przetestowany,
zanim istnieje transport czy sieć):

1. **Port + błędy** (`internal/<domain>/<port>.go`): mały interfejs po stronie
   konsumenta, `context.Context` jako pierwszy argument, sentinel errors
   (`var ErrNotFound = errors.New(...)`).
2. **Rdzeń** (`service.go`): `type Service struct { … }` + `func NewService(dep Port) *Service`
   — *accept interfaces, return structs*. Walidacja wejścia, delegacja do portu,
   błędy owijane `%w`.
3. **Testy rdzenia** (`service_test.go`): pakiet `<domain>_test` (czarna skrzynka),
   tabelaryczne, atrapa portu jako struct z pasującymi metodami (bez bibliotek
   mockujących).
4. **Adapter infra** (`internal/<adapter>/`): konkretny struct spełniający port
   *niejawnie*; przypnij to asercją `var _ <domain>.Port = (*Impl)(nil)`. Tłumacz
   błędy świata (`os.ErrNotExist`) na sentinele domeny.

---

## 2. Kontrakt `.proto`

`proto/<svc>/v1/<svc>.proto`:

```proto
syntax = "proto3";
package <svc>.v1;

option go_package = "github.com/bookly/<svc>/gen/<svc>/v1;<svc>v1";
option php_namespace = "App\\<Svc>\\Grpc";               // → PSR-4 App\ (patrz krok 5)
option php_metadata_namespace = "App\\<Svc>\\Grpc\\GPBMetadata";

service <Svc>Service {                                    // MUSI kończyć się na "Service" (buf lint)
  rpc DoThing(DoThingRequest) returns (DoThingResponse);
  rpc Upload(stream UploadRequest) returns (UploadResponse);   // client-streaming
  rpc Download(DownloadRequest) returns (stream DownloadResponse); // server-streaming
}

message DoThingRequest  { string key = 1; }              // nazwy: <Rpc>Request / <Rpc>Response
message DoThingResponse { int64 size = 1; }
```

Reguły, które oszczędzą rundy z `buf lint`: usługa z sufiksem `Service`, wiadomości
nazwane `<Rpc>Request`/`<Rpc>Response`, pakiet z wersją (`<svc>.v1`), plik w katalogu
pasującym do pakietu.

---

## 3. Codegen (buf)

`buf.yaml` (root repo):

```yaml
version: v2
modules:
  - path: proto
lint: { use: [STANDARD] }
breaking: { use: [FILE] }
```

`buf.gen.yaml` (root repo):

```yaml
version: v2
plugins:
  - local: protoc-gen-go            # Go: lokalne pluginy (offline)
    out: services/<svc>/gen
    opt: paths=source_relative
  - local: protoc-gen-go-grpc
    out: services/<svc>/gen
    opt: paths=source_relative
  - remote: buf.build/protocolbuffers/php   # PHP: brak lokalnego protoc → plugin zdalny (BSR)
    out: gen-php
  - remote: buf.build/grpc/php
    out: gen-php
```

```bash
export PATH="$PATH:$(go env GOPATH)/bin"
buf lint
buf generate            # PHP przez BSR → potrzebna sieć (proxy IPv4, patrz krok 0)
```

Wygenerowany kod **commitujemy** (Go w `services/<svc>/gen/`, PHP w
`gen-php/App/<Svc>/Grpc/`), żeby build/deploy nie wymagał `buf`/`protoc`.

---

## 4. Transport + bootstrap (Go)

**`internal/grpcserver/server.go`** — jedyna paczka importująca protobuf:

- `type service interface { … }` — wąski interfejs z tym, czego transport
  potrzebuje od rdzenia (znów: konsument definiuje).
- `type Server struct { <svc>v1.Unimplemented<Svc>ServiceServer; svc service }`
  (osadzenie `Unimplemented…` = forward-compat przy nowych RPC).
- Handlery tłumaczą request→typy rdzenia, wołają rdzeń, mapują błędy:
  `errors.Is(err, ErrNotFound) → codes.NotFound`, nieznane → `codes.Internal` z
  **generycznym** komunikatem (nie wyciekaj ścieżek/wewnętrznych detali).
- Streaming: client-stream reassembluj do `io.Reader`; server-stream pompuj
  `io.Reader` chunkami do `stream.Send`.

**`cmd/<svc>/main.go`** — cienki bootstrap:

- config z ENV (`MEDIA_ADDR`, `MEDIA_STORAGE_ROOT`, …),
- `run() error` (bo `main` nie zwraca błędu, a `os.Exit` pomija `defer`),
- **wiring w jednym miejscu**: `adapter.New(...) → domain.NewService(...) → grpcserver.New(...)`,
- `grpc.NewServer()` + rejestracja serwisu + **health** (`grpc/health`) + **reflection**,
- graceful shutdown: `signal.NotifyContext(…, SIGINT, SIGTERM)` + `gs.GracefulStop()`.

**Testy transportu** — `bufconn` (prawdziwy klient+serwer po kanale w pamięci):

```go
lis := bufconn.Listen(1 << 20)
gs := grpc.NewServer(); <svc>v1.Register<Svc>ServiceServer(gs, grpcserver.New(svc)); go gs.Serve(lis)
conn, _ := grpc.NewClient("passthrough:///bufnet",
    grpc.WithContextDialer(func(ctx context.Context, _ string) (net.Conn, error) { return lis.DialContext(ctx) }),
    grpc.WithTransportCredentials(insecure.NewCredentials()))
client := <svc>v1.New<Svc>ServiceClient(conn)
```

```bash
cd services/<svc>
gofmt -l . && go vet ./... && go test ./... -count=1
```

---

## 5. Strona Symfony (klient)

1. **Autoload wygenerowanego PHP** — `composer.json`:

   ```jsonc
   "autoload": {
     "psr-4": {
       "App\\": "src/",
       "App\\<Svc>\\Grpc\\": "gen-php/App/<Svc>/Grpc/"   // dłuższy prefiks wygrywa, brak kolizji
     }
   }
   ```

2. **Runtime gRPC/protobuf**:

   ```bash
   composer require google/protobuf      # runtime protobuf w czystym PHP (bez ext-protobuf)
   # ext-grpc jest OBOWIĄZKOWE do działania klienta — patrz krok 6
   ```

3. **Adapter** implementujący istniejący *port* domeny (np.
   `App\Ebook\Domain\Storage\FileStorage`), w `src/…/Infrastructure/…`:

   - w konstruktorze `new <Svc>ServiceClient($endpoint, ['credentials' => ChannelCredentials::createInsecure()])`,
   - unary: `[$resp, $status] = $client->Method($req)->wait();` + sprawdzenie `$status->code === \Grpc\STATUS_OK`,
   - client-stream (upload): `$call = $client->Upload(); $call->write($req); …; [$r,$s] = $call->wait();`,
   - server-stream (download): `foreach ($call->responses() as $chunk) { … } $call->getStatus();`,
   - mapuj status gRPC na wyjątek domeny.

4. **Wpięcie w kontenerze** (`config/services.yaml`) — trzymaj przełącznik do czasu
   cutoveru:

   ```yaml
   # aktywna implementacja portu — przełączenie = zmiana tego aliasu
   App\Ebook\Domain\Storage\FileStorage:
       alias: App\Ebook\Infrastructure\Storage\FlysystemFileStorage

   App\Ebook\Infrastructure\Storage\GrpcFileStorage:
       arguments:
           $endpoint: '%env(MEDIA_GRPC_ENDPOINT)%'
   ```

   `.env`: `MEDIA_GRPC_ENDPOINT=localhost:8090`.

   > Dwie implementacje tego samego interfejsu ⇒ **musi** być jawny alias, inaczej
   > autowiring się wysypie. String `$endpoint` **musi** być zbindowany.

5. **Weryfikacja bez uruchamiania** (nie wymaga ext-grpc):

   ```bash
   composer dump-autoload
   php -l src/…/GrpcFileStorage.php
   php bin/console lint:container
   php bin/phpunit
   ```

---

## 6. `ext-grpc` lokalnie (do faktycznego uruchomienia)

Klient gRPC w PHP wymaga rozszerzenia C `grpc`. PECL bunduje cały C-core, więc
kompilacja trwa kilkanaście–kilkadziesiąt minut.

```bash
# potrzebne: phpize, php-config, gcc, make (bez pecl da się ręcznie)
curl -sL -o /tmp/grpc.tgz https://pecl.php.net/get/grpc     # (proxy IPv4 jeśli trzeba)
tar xzf /tmp/grpc.tgz -C /tmp && cd /tmp/grpc-*/
phpize
./configure
make -j"$(nproc)"                                            # długo!
# → modules/grpc.so
```

Załaduj bez ruszania systemowego `php.ini`:

```bash
php -d extension=/tmp/grpc-*/modules/grpc.so twoj_skrypt.php
# albo na stałe: skopiuj grpc.so do katalogu rozszerzeń i dodaj `extension=grpc.so`
# do pliku w `Scan this dir` (u nas /etc/php/conf.d)
```

---

## 7. Uruchomienie i spięcie obu serwisów (lokalnie)

```bash
# 1) serwis Go
cd services/<svc> && go build -o /tmp/<svc> ./cmd/<svc>
MEDIA_ADDR=:8090 MEDIA_STORAGE_ROOT="$PWD/../../var/media" /tmp/<svc> &

# 2) sprawdź, że słucha
ss -ltn | grep 8090

# 3) (opcjonalnie) podejrzyj API bez .proto dzięki reflection
grpcurl -plaintext localhost:8090 list

# 4) wywołaj z PHP przez adapter (z załadowanym ext-grpc)
php -d extension=/path/grpc.so scratchpad/smoke.php
```

Cutover produkcyjny: przełącz alias portu na adapter gRPC (krok 5.4). Domena się
nie zmienia — dostaje inną implementację tego samego interfejsu.

---

## 8. Deploy (Upsun, multi-app)

`.upsun/config.yaml` — druga aplikacja z własnym `source.root`:

```yaml
applications:
  app:                          # Symfony
    source: { root: "/" }
    type: "php:8.4"             # + rozszerzenie grpc w obrazie
  <svc>:                        # serwis Go
    source: { root: "services/<svc>" }
    type: "golang:1.26"
    web:
      commands: { start: "./<svc>" }
```

- Wolumen z danymi montuj pod **serwisem Go** (to on jest właścicielem bajtów);
  Symfony gada z nim po sieci wewnętrznej (`MEDIA_GRPC_ENDPOINT=<svc>.internal:8090`).
- `ext-grpc` w obrazie PHP; kod wygenerowany jest commitowany, więc build nie
  potrzebuje `buf`/`protoc`.
- Szczegóły platformy: [`../deploy-upsun.md`](../deploy-upsun.md).

---

## Checklista idiomów Go (żeby nie było spaghetti)

| ✅ Rób | ❌ Nie rób |
|---|---|
| pakiety **per funkcja** (`media`, `blob`) | foldery per warstwa (`services/`, `models/`) |
| interfejs u **konsumenta**, mały | jeden wielki `interface` z 10 metodami |
| interfejs dopiero przy realnej granicy / ≥2 impl. | interfejs „na zapas" do wszystkiego |
| wiring w `main()` ręcznie | kontener DI / autowiring |
| błędy jako wartości, `%w`, `errors.Is` | `panic` do sterowania przepływem |
| `return struct`, `accept interface` | zwracanie interfejsów z konstruktorów |
| `context.Context` pierwszym argumentem IO | globalny stan pakietowy |
| eksport (duża litera) tylko gdy trzeba | wszystko publiczne + gettery/settery |

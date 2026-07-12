# lockerbe — Server Smart Locker (Laravel 13)

Backend del sistema **smart locker per guardaroba**. Gestisce tenant, armadi, vani,
sessioni, pagamenti, comandi di apertura, aggiornamenti OTA e audit log.

📄 Piano implementativo dettagliato: `memory/plan_locker_server.md` nel repo
[`locker`](https://git.home.varitest.ovh/smp-webmaster/locker.git).

## Stack

| Ambito | Scelta |
|---|---|
| Framework | **Laravel 13.19** |
| PHP | **^8.3** |
| Database | **PostgreSQL 16+** (necessario per Row-Level Security) |
| Cache / Code | Redis + Horizon |
| Auth API | Laravel Sanctum |
| RBAC | spatie/laravel-permission |
| Canale device | MQTT (`php-mqtt/laravel-client`) |
| Test | Pest |

> ⚠️ Laravel installa **SQLite** di default, ma questo progetto **richiede PostgreSQL**:
> l'isolamento fra tenant si appoggia alla **Row-Level Security**, che SQLite non ha.

## Requisiti

- PHP 8.3+ (estensione `pdo_pgsql`) · Composer 2
- Docker (per Postgres e Redis: `docker-compose.yml`)
- Un broker MQTT (Mosquitto / EMQX) — solo da F5 in poi

## Setup

```bash
docker compose up -d       # PostgreSQL 16 + Redis 7
composer install
cp .env.example .env
php artisan key:generate
composer migrate           # NON `php artisan migrate` — vedi sotto
php artisan serve
```

### I due ruoli del database, e perché

Il `docker-compose` crea **due** ruoli Postgres, e la distinzione è una misura di
sicurezza, non uno stile:

| Ruolo | Cosa fa | Perché |
|---|---|---|
| `locker_owner` | possiede lo schema, esegue **solo** le migration | — |
| `locker_app` | il runtime dell'applicazione | **non** superuser, **non** owner, `NOBYPASSRLS` |

L'isolamento fra tenant si appoggia alla **Row-Level Security** di Postgres. Ma le policy
RLS **non si applicano ai superuser**, e non si applicano al proprietario delle tabelle
salvo `FORCE`. Se l'applicazione girasse come proprietario o superuser, l'isolamento
sarebbe scavalcato **in silenzio**: nessun errore, nessun test rosso, e i vani di un
cliente visibili (o apribili) da un altro.

Conseguenza pratica: `locker_app` **non ha `CREATE`** sullo schema, quindi
`php artisan migrate` fallisce di proposito. Usa `composer migrate` (o
`--database=pgsql_owner`). Vale anche per i test: lo schema viene creato dall'owner, le
query girano come app — vedi `tests/Concerns/RefreshDatabaseAsOwner.php`.

## Modalità MOCK (sviluppo)

Pagamento e identità carta sono **simulabili con un bottone**, senza provider di pagamento
né hardware NFC: permettono di testare l'intero flusso end-to-end.

```env
LOCKER_PAYMENT_DRIVER=mock     # mock | nexi
LOCKER_IDENTITY_DRIVER=mock    # mock | nfc
LOCKER_MOCK_PANEL=true         # mai in produzione
```

Endpoint mock (solo fuori produzione):

| Endpoint | Simula |
|---|---|
| `POST /api/v1/mock/payments/{payment}/confirm` | pagamento riuscito → **il vano si apre** |
| `POST /api/v1/mock/payments/{payment}/fail` | pagamento fallito → sessione annullata |
| `POST /api/v1/mock/identity/tap` | tap della carta (registra al primo uso, poi riapre) |
| `POST /api/v1/mock/devices/{cabinet}/ack` | ACK del dispositivo |
| `POST /api/v1/mock/devices/{cabinet}/heartbeat` | dispositivo online |

I mock usano **la stessa code-path** dei provider reali: passare al reale significa
implementare i contratti `PaymentProvider` / `IdentityProvider` e cambiare una variabile
d'ambiente. Nessuna riscrittura del resto.

## Concetti

- **Tenant** → cliente, con pannello di controllo dedicato
- **Cabinet** (Armadio) → 1 dispositivo FCV5003, contiene N vani
- **Locker** (vano) → indirizzato via RS-485 (board + canale)
- **Session** → rapporto temporaneo utente ↔ vano (pagamento, riaperture, checkout)
- **Command** → ordine di apertura verso il dispositivo: **firmato, idempotente, con scadenza**

## Regole non negoziabili

1. **Ogni comando di apertura ha un TTL.** Un `open` accodato mentre l'armadio è offline e
   consegnato ore dopo aprirebbe un vano pieno di roba nel cuore della notte. Se il
   dispositivo è offline l'API **fallisce esplicitamente** (`409`), invece di promettere
   l'apertura per dopo.
2. **L'isolamento fra tenant è imposto dal database** (RLS), non dalla disciplina di chi
   scrive le query.
3. **Ogni operazione sui vani finisce nell'audit log** (append-only, hash-chain): è la prova
   di chi ha aperto cosa e quando.
4. **L'OTA si fa a stadi**, mai in push massivo: un pacchetto difettoso metterebbe fuori uso
   tutti gli armadi di tutti i clienti contemporaneamente.

## Qualità

```bash
composer check     # Pint (stile) + PHPStan livello 8 (Larastan) + Pest
vendor/bin/pest    # solo i test
```

I test girano su **PostgreSQL**, mai su SQLite in memoria: il test più importante del
progetto — l'isolamento fra tenant — verifica la RLS, che SQLite non ha. Su SQLite
passerebbe sempre, anche con l'isolamento rotto. Stessa regola in CI
(`.gitea/workflows/ci.yml`).

## Stato

**F0 — Fondamenta: completata.** Postgres (due ruoli) + Redis via Docker, Sanctum, spatie
permission, Pest, Pint, Larastan livello 8, CI, PK UUID v7, `config/locker.php`.

Prossima: **F1 — Tenancy + RBAC + Audit**, che viene *prima* di qualsiasi comando fisico.
Fasi e sottofasi: §21 del piano (`memory/plan_locker_server.md` nel repo `locker`).

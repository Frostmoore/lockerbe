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

- PHP 8.3+ · Composer 2
- PostgreSQL 16+
- Redis
- Un broker MQTT (Mosquitto / EMQX) per il dialogo con i dispositivi

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configura il database in `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lockerbe
DB_USERNAME=lockerbe
DB_PASSWORD=...
```

Poi:

```bash
php artisan migrate
php artisan serve
```

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

## Test

```bash
php artisan test
```

## Stato

Scheletro Laravel creato. Implementazione da avviare dalle milestone **M0** (fondamenta) e
**M1** (tenancy + RBAC + audit) descritte nel piano — le fondamenta **prima** di qualunque
comando fisico.

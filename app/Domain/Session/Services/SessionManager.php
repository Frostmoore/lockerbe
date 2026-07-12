<?php

namespace App\Domain\Session\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Contracts\CommandDispatcher;
use App\Domain\Locker\Services\LockerInventoryService;
use App\Domain\Session\Exceptions\IllegalTransitionException;
use App\Events\PaymentConfirmed;
use App\Events\PaymentFailed;
use App\Events\SessionClosed;
use App\Events\SessionCreated;
use App\Models\Cabinet;
use App\Models\Identity;
use App\Models\Payment;
use App\Models\Session;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * L'UNICO punto del sistema che muove lo stato di una sessione o di un vano.
 *
 * "Unico" e' una regola, non una descrizione: se lo stato di un vano potesse cambiare da
 * due posti diversi, prima o poi i due si contraddirebbero — e un vano contraddittorio e'
 * un vano che si apre quando non dovrebbe. Il rispetto della regola e' verificabile con un
 * grep: nessun altro file scrive `sessions.status` o `lockers.status`.
 *
 * Le transizioni ammesse sono quelle e SOLO quelle della tabella §7.1 del piano. Tutto il
 * resto solleva IllegalTransitionException (→ 422). Non si "aggiusta" niente in silenzio.
 */
final class SessionManager
{
    public function __construct(
        private readonly LockerInventoryService $inventory,
        private readonly CommandDispatcher $commands,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * — → `created`.
     *
     * Assegna il primo vano libero, lo mette in `reserved`, e da' al cliente una finestra
     * di tempo per pagare. Il token pubblico e' cio' che si portera' via (link/QR) per
     * riaprire dal telefono senza avere un account.
     *
     * @return array{session: Session, token: string} il token in chiaro esiste SOLO qui:
     *                                                nel database ne resta l'hash
     */
    public function request(Cabinet $cabinet, ?int $amountCents = null): array
    {
        $locker = $this->inventory->assignFirstFree($cabinet);

        $rawToken = Str::random(40);

        $session = new Session([
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
            'status' => 'created',
            'amount_cents' => $amountCents ?? $this->tariffFor($cabinet),
            'currency' => 'EUR',
            'public_token_hash' => Identity::hashToken($rawToken),
            'reserved_until' => now()->addSeconds((int) config('locker.reservation.ttl')),
            'expires_at' => $this->endOfNightFor($cabinet),
            'meta' => [],
        ]);
        $session->save();

        $locker->update(['current_session_id' => $session->id]);

        $this->audit->log('session.request', [
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
            'session_id' => $session->id,
            'context' => ['locker_number' => $locker->number, 'amount_cents' => $session->amount_cents],
        ]);

        SessionCreated::dispatch($session);

        return ['session' => $session, 'token' => $rawToken];
    }

    /**
     * `created` --payment.confirmed--> `active`.
     *
     * Il vano diventa `occupied` e parte il comando **open(store)**: e' il momento in cui
     * l'armadietto si apre per far mettere dentro il cappotto.
     *
     * ⚠️ **Idempotente.** Un provider di pagamento rimanda lo stesso webhook piu' volte —
     * e il mock ha un bottone che si puo' cliccare due volte. Se ogni consegna riaprisse il
     * vano, il cappotto resterebbe alla merce' di chiunque passi.
     */
    public function confirmPayment(Payment $payment): Session
    {
        /** @var Session $session */
        $session = $payment->session()->firstOrFail();

        // Gia' confermato: non si fa nulla, e soprattutto non si emette un secondo `open`.
        if ($payment->isConfirmed() && $session->isActive()) {
            return $session;
        }

        if ($session->status !== 'created') {
            throw new IllegalTransitionException($session->status, 'payment.confirmed');
        }

        return DB::transaction(function () use ($payment, $session): Session {
            $payment->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ])->save();

            $session->forceFill([
                'status' => 'active',
                'payment_id' => $payment->id,
                'paid_at' => now(),
            ])->save();

            $locker = $session->locker()->firstOrFail();
            $locker->update(['status' => 'occupied', 'last_opened_at' => now()]);

            $commandId = $this->commands->issueOpen($locker, 'store');

            $this->audit->log('payment.confirmed', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $locker->id,
                'session_id' => $session->id,
                'command_id' => $commandId,
                'context' => ['provider' => $payment->provider, 'amount_cents' => $payment->amount_cents],
            ]);

            PaymentConfirmed::dispatch($payment);

            return $session;
        });
    }

    /**
     * `created` --payment.failed--> `cancelled`. Il vano torna libero.
     */
    public function failPayment(Payment $payment): Session
    {
        /** @var Session $session */
        $session = $payment->session()->firstOrFail();

        if ($session->status !== 'created') {
            throw new IllegalTransitionException($session->status, 'payment.failed');
        }

        return DB::transaction(function () use ($payment, $session): Session {
            $payment->forceFill(['status' => 'failed'])->save();

            $this->releaseLocker($session);
            $session->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();

            $this->audit->log('payment.failed', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'result' => 'fail',
                'error_code' => 'payment_failed',
            ]);

            PaymentFailed::dispatch($payment);

            return $session;
        });
    }

    /**
     * `active` --reopen--> `active`. Il cliente riapre il proprio vano durante la serata.
     *
     * Comando **open(reopen)**. Il vano resta `occupied`: la roba e' ancora dentro.
     */
    public function reopen(Session $session, Identity $identity): string
    {
        if (! $session->isActive()) {
            throw new IllegalTransitionException($session->status, 'reopen');
        }

        return DB::transaction(function () use ($session, $identity): string {
            $session->increment('reopen_count');

            $locker = $session->locker()->firstOrFail();
            $locker->update(['last_opened_at' => now()]);

            $identity->forceFill(['last_used_at' => now()])->save();

            $commandId = $this->commands->issueOpen($locker, 'reopen');

            $this->audit->log('session.reopen', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $locker->id,
                'session_id' => $session->id,
                'command_id' => $commandId,
                'context' => ['reopen_count' => $session->reopen_count, 'identity_type' => $identity->type],
            ]);

            return $commandId;
        });
    }

    /**
     * `active` --checkout--> `closed`. Il cliente si riprende la roba e libera il vano.
     *
     * Comando **open(checkout)**, poi il vano torna `free` e puo' essere riassegnato.
     */
    public function checkout(Session $session): string
    {
        if (! $session->isActive()) {
            throw new IllegalTransitionException($session->status, 'checkout');
        }

        return DB::transaction(function () use ($session): string {
            $locker = $session->locker()->firstOrFail();

            $commandId = $this->commands->issueOpen($locker, 'checkout');

            $session->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

            // free, non `checkout`: lo stato intermedio del piano §7.2 serve a distinguere
            // "aperto per il ritiro" da "gia' riassegnabile", e diventa osservabile solo in
            // F5, quando il device confermera' la chiusura fisica dello sportello. Finche'
            // l'ack non esiste, tenere il vano in `checkout` significherebbe non renderlo
            // mai piu' assegnabile.
            $locker->update([
                'status' => 'free',
                'current_session_id' => null,
                'last_opened_at' => now(),
            ]);

            $this->audit->log('session.checkout', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $locker->id,
                'session_id' => $session->id,
                'command_id' => $commandId,
            ]);

            SessionClosed::dispatch($session);

            return $commandId;
        });
    }

    /**
     * `created` --reserved_until scaduto--> `cancelled`. Il vano torna libero.
     *
     * Senza questo, un vano prenotato e mai pagato resterebbe bloccato per sempre: un
     * armadietto vuoto che il sistema crede occupato.
     *
     * @return int quante prenotazioni sono state annullate
     */
    public function cancelExpiredReservations(): int
    {
        $expired = Session::query()
            ->where('status', 'created')
            ->where('reserved_until', '<', now())
            ->get();

        foreach ($expired as $session) {
            DB::transaction(function () use ($session): void {
                $this->releaseLocker($session);
                $session->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();

                $this->audit->log('session.reservation_expired', [
                    'cabinet_id' => $session->cabinet_id,
                    'locker_id' => $session->locker_id,
                    'session_id' => $session->id,
                ]);
            });
        }

        return $expired->count();
    }

    /**
     * `active` --expires_at scaduto--> `closed`. La fine della serata.
     *
     * ⚠️ Chiusura forzata: dentro c'e' ancora la roba di qualcuno che non e' tornato a
     * riprendersela. Il vano NON torna libero da solo — resta `occupied` e la sessione si
     * chiude, perche' lo staff deve poter vedere quali armadietti sono rimasti pieni a fine
     * serata e svuotarli a mano. Liberarli automaticamente significherebbe riassegnare un
     * vano con dentro il cappotto di qualcun altro.
     *
     * @return int quante sessioni sono state chiuse
     */
    public function closeExpiredSessions(): int
    {
        $expired = Session::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $session) {
            DB::transaction(function () use ($session): void {
                $session->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

                $this->audit->log('session.expired', [
                    'cabinet_id' => $session->cabinet_id,
                    'locker_id' => $session->locker_id,
                    'session_id' => $session->id,
                    'context' => ['abbandonato' => true],
                ]);

                SessionClosed::dispatch($session);
            });
        }

        return $expired->count();
    }

    /** Il vano torna disponibile e si dimentica della sessione. */
    private function releaseLocker(Session $session): void
    {
        $session->locker()->firstOrFail()->update([
            'status' => 'free',
            'current_session_id' => null,
        ]);
    }

    /** Tariffa del locale, in centesimi. */
    private function tariffFor(Cabinet $cabinet): int
    {
        $tenant = $cabinet->tenant()->firstOrFail();

        return (int) ($tenant->settings['tariff_cents'] ?? 500);
    }

    /**
     * La "fine serata" nel fuso del tenant (piano §7.4).
     *
     * ⚠️ **Mai logica sul giorno solare.** Un guardaroba chiude alle 6 del mattino: quella
     * e' ancora "la serata di ieri". Calcolare "fine giornata" sulla data odierna
     * chiuderebbe le sessioni a mezzanotte, cioe' nel momento di massimo affollamento, con
     * i cappotti ancora dentro e i clienti ancora a ballare.
     *
     * Ogni sessione porta quindi un `expires_at` esplicito, calcolato **nel fuso del
     * locale**: la prossima occorrenza dell'orario di chiusura, che di norma cade il giorno
     * dopo.
     */
    private function endOfNightFor(Cabinet $cabinet): CarbonImmutable
    {
        $tenant = $cabinet->tenant()->firstOrFail();

        $timezone = $tenant->timezone;
        $closingTime = (string) ($tenant->settings['closing_time'] ?? '06:00');

        [$hour, $minute] = array_map('intval', explode(':', $closingTime));

        $localNow = CarbonImmutable::now($timezone);
        $closing = $localNow->setTime($hour, $minute);

        // Se l'orario di chiusura di oggi e' gia' passato, si intende quello di domani.
        if ($closing->lessThanOrEqualTo($localNow)) {
            $closing = $closing->addDay();
        }

        return $closing->utc();
    }
}

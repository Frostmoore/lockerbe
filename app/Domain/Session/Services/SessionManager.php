<?php

namespace App\Domain\Session\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Contracts\CommandDispatcher;
use App\Domain\Command\Exceptions\DeviceOfflineException;
use App\Domain\Identity\Services\IdentityIssuer;
use App\Domain\Locker\Services\LockerInventoryService;
use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Payment\PaymentInstruction;
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
        private readonly IdentityIssuer $identities,
        private readonly PaymentProvider $payments,
    ) {}

    /**
     * — → `created`.
     *
     * Assegna il primo vano libero, lo mette in `reserved`, e da' al cliente una finestra
     * di tempo per pagare. Il token pubblico e' cio' che si portera' via (link/QR) per
     * riaprire dal telefono senza avere un account.
     *
     * @return array{session: Session, token: string, payment: PaymentInstruction} il token in
     *                                                                             chiaro esiste SOLO qui: nel database ne
     *                                                                             resta l'hash
     */
    public function request(Cabinet $cabinet, ?int $amountCents = null, string $method = 'qr'): array
    {
        $locker = $this->inventory->assignFirstFree($cabinet);

        $rawToken = Str::random(40);

        $session = new Session([
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
            'status' => 'created',
            'amount_cents' => $amountCents ?? $this->tariffFor($cabinet),
            'currency' => 'EUR',

            // ⚠️ Deciso dal cliente al chiosco, e da qui in poi non si tocca piu': e' cio' che
            // stabilisce come otterra' l'identita' — un codice per email, o il token della
            // sua carta.
            'payment_method' => $method === 'nfc' ? 'nfc' : 'qr',
            'public_token_hash' => Identity::hashToken($rawToken),
            'reserved_until' => now()->addSeconds($this->reservationTtlFor($cabinet)),
            'expires_at' => $this->endOfNightFor($cabinet),
            'meta' => [],
        ]);
        $session->save();

        $locker->update(['current_session_id' => $session->id]);

        /*
         * ⚠️ IL PAGAMENTO SI CREA QUI, non nel chiamante.
         *
         * Prima lo creava il KioskController, e nessun altro. Risultato: qualunque altra strada
         * per aprire una sessione — un test, un webhook, domani un secondo chiosco — produceva
         * una sessione **senza pagamento**, cioe' una sessione che non si puo' confermare.
         *
         * ⚠️ Il token pubblico entra nell'istruzione perche' **il QR deve portare a una pagina
         * vera**: quella su cui il cliente paga e lascia l'email. Un QR con dentro uno schema
         * fantasia (`locker://…`) e' un QR che nessun telefono sa aprire.
         */
        $istruzione = $this->payments->create($session, $rawToken);

        $payment = Payment::create([
            'session_id' => $session->id,
            'provider' => $istruzione->provider,
            'provider_ref' => $istruzione->providerRef,
            'amount_cents' => $istruzione->amountCents,
            'currency' => $istruzione->currency,
            'status' => 'created',
            'payload' => [],
        ]);

        $session->forceFill(['payment_id' => $payment->id])->save();

        $this->audit->log('session.request', [
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
            'session_id' => $session->id,
            'context' => ['locker_number' => $locker->number, 'amount_cents' => $session->amount_cents],
        ]);

        SessionCreated::dispatch($session);

        return ['session' => $session, 'token' => $rawToken, 'payment' => $istruzione];
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

            /*
             * ⚠️ L'ARMADIO PUO' ESSERE OFFLINE PROPRIO ADESSO — e i soldi sono gia' stati presi.
             *
             * E' l'unico punto del sistema in cui un armadio irraggiungibile **non** puo'
             * semplicemente far fallire l'operazione: il cliente ha pagato. Rifiutare qui
             * lascerebbe un pagamento incassato senza sessione, cioe' il peggio dei due mondi.
             *
             * Quindi la sessione diventa comunque `active` (il vano e' suo, l'ha pagato), ma
             * **nessun comando viene accodato** — la difesa §8.4 resta intatta: non si promette
             * un'apertura per dopo. L'apertura fallita finisce nell'audit, il pannello mostra il
             * vano occupato e non apribile, e il cliente potra' riaprirlo (o lo staff per lui)
             * appena l'armadio torna.
             *
             * In pratica e' un caso di bordo: il chiosco E' l'armadio, quindi se e' spento non
             * c'e' nessuno li' davanti a pagare. Puo' succedere solo se muore fra il QR mostrato
             * e il pagamento completato sul telefono.
             */
            $commandId = null;

            try {
                $commandId = $this->commands->issueOpen($locker, 'store');
            } catch (DeviceOfflineException $e) {
                $this->audit->log('session.store_open_failed', [
                    'cabinet_id' => $session->cabinet_id,
                    'locker_id' => $locker->id,
                    'session_id' => $session->id,
                    'result' => 'fail',
                    'error_code' => 'device_offline',
                    'context' => ['pagamento_incassato' => true, 'vano_non_aperto' => true],
                ]);
            }

            /*
             * ⚠️⚠️ L'IDENTITA' NASCE QUI, E SOLO QUI.
             *
             * E' il momento in cui il sistema puo' sapere con certezza *chi* potra' riaprire
             * quel vano: chi ha appena pagato **e' li' davanti**. Farlo dopo — con un tap
             * "quando capita" — significava che chi pagava col QR non riceveva nessuna
             * identita', e poi premeva "ho finito" senza che succedesse niente.
             *
             * Sta DENTRO confirmPayment, non nei chiamanti, perche' i chiamanti sono tanti e
             * cresceranno: la pagina di pagamento, il chiosco con la carta, domani il webhook
             * di Nexi. Se l'identita' si creasse fuori, basterebbe un chiamante nuovo che se
             * ne dimentica per avere clienti che non possono riprendersi il cappotto.
             */
            $this->identities->issueFor($session->refresh(), $payment);

            $this->audit->log('payment.confirmed', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $locker->id,
                'session_id' => $session->id,
                'command_id' => $commandId,
                'context' => [
                    'provider' => $payment->provider,
                    'amount_cents' => $payment->amount_cents,
                    'method' => $session->payment_method,
                ],
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
     * `created` --annullata dal cliente--> `cancelled`. Il vano torna **subito** libero.
     *
     * ⚠️ **Il bottone "annulla" non è cortesia: è inventario.** Senza, un cliente che cambia
     * idea davanti alla schermata di pagamento lascia il vano bloccato per tutta la durata
     * della prenotazione — e in una serata di punta bastano pochi ripensamenti per far
     * risultare pieno un armadio mezzo vuoto. Il cliente successivo se ne va.
     *
     * ⚠️ Solo da `created`: non si annulla una sessione **pagata**. Se i soldi sono stati
     * presi, questo non è un annullamento — è un rimborso, ed è un'altra cosa (e oggi non
     * esiste: vedi il debito).
     */
    public function cancelReservation(Session $session): Session
    {
        if ($session->status !== 'created') {
            throw new IllegalTransitionException($session->status, 'reservation.cancelled');
        }

        return DB::transaction(function () use ($session): Session {
            /*
             * ⚠️ Il pagamento resta `created`, e va bene così: **non è mai stato tentato**.
             * Marcarlo `failed` racconterebbe una bugia — che qualcosa è andato storto — e
             * quella bugia finirebbe nelle statistiche di conversione, dove un cliente che ha
             * cambiato idea diventerebbe un pagamento rifiutato.
             */
            $this->releaseLocker($session);
            $session->forceFill(['status' => 'cancelled', 'closed_at' => now()])->save();

            $this->audit->log('session.cancelled', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'context' => ['by' => 'customer'],
            ]);

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
            // ⚠️ Se era in corso una riconsegna, riaprire la ANNULLA: il cliente si e'
            // accorto di aver dimenticato qualcosa nella tasca del cappotto. Il vano torna
            // suo, come se non avesse mai premuto "ho finito".
            $riconsegnaAnnullata = $session->checkout_pending_at !== null;

            $session->increment('reopen_count');

            if ($riconsegnaAnnullata) {
                $session->forceFill(['checkout_pending_at' => null])->save();
            }

            $locker = $session->locker()->firstOrFail();
            $locker->update([
                'status' => 'occupied',       // torna occupato anche se era in `checkout`
                'last_opened_at' => now(),
            ]);

            $identity->forceFill(['last_used_at' => now()])->save();

            $commandId = $this->commands->issueOpen($locker, 'reopen');

            $this->audit->log('session.reopen', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $locker->id,
                'session_id' => $session->id,
                'command_id' => $commandId,
                'context' => [
                    'reopen_count' => $session->reopen_count,
                    'identity_type' => $identity->type,
                    'riconsegna_annullata' => $riconsegnaAnnullata,
                ],
            ]);

            return $commandId;
        });
    }

    /**
     * `active` --checkout--> `active` + riconsegna in corso (vano `checkout`).
     *
     * ⚠️ IL VANO NON DIVENTA LIBERO QUI, e questo e' il cuore del problema.
     *
     * Il sistema **non puo' sapere se il vano e' vuoto**: sa (forse, dipende da D5) se lo
     * sportello e' chiuso, non se dentro c'e' ancora un cappotto. E liberare un vano per
     * sbaglio significa assegnarlo a un altro cliente **con dentro la roba di qualcuno** —
     * il danno peggiore possibile. Tenerlo occupato per sbaglio costa qualche euro di
     * rotazione, e lo staff lo recupera in trenta secondi.
     *
     * Quindi: il vano si apre, entra in stato `checkout` ("aperto per il ritiro, non ancora
     * riassegnabile") e resta del cliente. Diventa `free` solo con una **conferma esplicita**
     * (`confirmCheckout`): sportello richiuso, finestra di cortesia scaduta, o operatore.
     *
     * La sessione resta `active` di proposito: se il cliente si accorge di aver dimenticato
     * il telefono nella tasca del cappotto e ripassa la carta, `reopen()` **annulla la
     * riconsegna** e tutto torna com'era. Costa niente, e salva la situazione piu'
     * imbarazzante possibile.
     */
    public function checkout(Session $session): string
    {
        if (! $session->isActive()) {
            throw new IllegalTransitionException($session->status, 'checkout');
        }

        return DB::transaction(function () use ($session): string {
            $locker = $session->locker()->firstOrFail();

            $commandId = $this->commands->issueOpen($locker, 'checkout');

            $session->forceFill(['checkout_pending_at' => now()])->save();

            $locker->update(['status' => 'checkout', 'last_opened_at' => now()]);

            $this->audit->log('session.checkout_requested', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $locker->id,
                'session_id' => $session->id,
                'command_id' => $commandId,
            ]);

            return $commandId;
        });
    }

    /**
     * Chiude davvero la riconsegna: sessione `closed`, vano `free` e riassegnabile.
     *
     * Le tre strade che portano qui, in ordine di affidabilita':
     *
     *   `device`  lo sportello e' stato **richiuso** — la conferma vera. Richiede che la
     *             scheda serrature sappia leggere lo stato dello sportello (⚠️ **D5**,
     *             ancora ignota: serve il datasheet della VF203_V12). Arrivera' in F5.
     *   `timeout` la finestra di cortesia e' scaduta (`locker.checkout.grace`). E' il
     *             ripiego finche' D5 non e' sbloccata: imperfetto ma necessario, altrimenti
     *             senza sensore nessun vano tornerebbe mai libero.
     *   `staff`   un operatore ha guardato dentro e ha confermato.
     */
    public function confirmCheckout(Session $session, string $closedBy): void
    {
        if ($session->checkout_pending_at === null) {
            throw new IllegalTransitionException($session->status, 'checkout.confirm');
        }

        DB::transaction(function () use ($session, $closedBy): void {
            $session->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $closedBy,
                'checkout_pending_at' => null,
            ])->save();

            $this->releaseLocker($session);

            $this->audit->log('session.checkout', [
                'cabinet_id' => $session->cabinet_id,
                'locker_id' => $session->locker_id,
                'session_id' => $session->id,
                'context' => ['closed_by' => $closedBy],
            ]);

            SessionClosed::dispatch($session);
        });
    }

    /**
     * Finestra di cortesia scaduta ⇒ si chiudono le riconsegne rimaste in sospeso.
     *
     * ⚠️ Ripiego, non soluzione. La conferma giusta e' lo sportello richiuso, ma finche' D5
     * e' aperta non sappiamo se la scheda serrature sappia dirlo. Senza questo timer, con un
     * device muto, nessun vano tornerebbe **mai** libero dopo una riconsegna.
     *
     * @return int quante riconsegne sono state chiuse
     */
    public function finalizePendingCheckouts(): int
    {
        $grace = (int) config('locker.checkout.grace');

        $pending = Session::query()
            ->where('status', 'active')
            ->whereNotNull('checkout_pending_at')
            ->where('checkout_pending_at', '<', now()->subSeconds($grace))
            ->get();

        foreach ($pending as $session) {
            $this->confirmCheckout($session, 'timeout');
        }

        return $pending->count();
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
                $session->forceFill([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => 'expiry',
                    'checkout_pending_at' => null,
                ])->save();

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
    /**
     * Quanto costa un vano di QUESTO armadio.
     *
     * ⚠️ **Il prezzo scende a cascata**: armadio → locale → default di piattaforma.
     *
     * Il null sull'armadio non e' un buco: significa *"segui il listino del locale"*. Copiarci
     * dentro il prezzo del locale sarebbe peggio — il giorno che il gestore ritocca il listino,
     * gli armadi che non ha toccato resterebbero al prezzo vecchio, e nessuno se ne accorgerebbe
     * finche' non arriva un cliente a lamentarsi.
     *
     * ⚠️ Sempre in **centesimi**: i float non tengono i soldi.
     */
    /**
     * Quanto dura la prenotazione su QUESTO armadio, in secondi.
     *
     * ⚠️ Stessa cascata del prezzo — **armadio → locale → default** — e per lo stesso motivo:
     * il `null` significa *"segui il locale"*, non *"non impostato"*.
     *
     * ⚠️ E' una decisione **commerciale**, non tecnica. Un locale di passaggio vuole una
     * finestra corta (il vano si libera subito se il cliente ci ripensa); un teatro, dove la
     * gente paga con calma prima dello spettacolo, la vuole lunga — una prenotazione che scade
     * mentre il cliente cerca gli occhiali e' un cliente arrabbiato.
     */
    private function reservationTtlFor(Cabinet $cabinet): int
    {
        if ($cabinet->reservation_ttl !== null) {
            return $cabinet->reservation_ttl;
        }

        $tenant = $cabinet->tenant()->firstOrFail();

        return (int) ($tenant->settings['reservation_ttl'] ?? config('locker.reservation.ttl'));
    }

    private function tariffFor(Cabinet $cabinet): int
    {
        if ($cabinet->tariff_cents !== null) {
            return $cabinet->tariff_cents;
        }

        $tenant = $cabinet->tenant()->firstOrFail();

        return (int) ($tenant->settings['tariff_cents'] ?? config('locker.tariff.default'));
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

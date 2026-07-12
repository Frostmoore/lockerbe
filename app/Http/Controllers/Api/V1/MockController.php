<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Contracts\IdentityProvider;
use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Session\Services\SessionManager;
use App\Http\Resources\SessionResource;
use App\Models\Cabinet;
use App\Models\Identity;
use App\Models\Payment;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * I BOTTONI (piano §12).
 *
 * Servono a una cosa sola: **vedere il sistema funzionare oggi**, senza Nexi, senza carte
 * NFC e senza il FCV5003 — che non e' disponibile a data da destinarsi. Non sono un
 * ripiego: sono l'unico modo che abbiamo, adesso, di verificare che il flusso sia giusto.
 *
 * ⚠️ **Doppio cancello**: queste rotte esistono solo se `APP_ENV != production` **e**
 * `locker.mock_panel` e' acceso. In produzione devono rispondere **404** — non 403: non
 * devono nemmeno esistere. C'e' un test che lo verifica.
 *
 * ⚠️ I mock passano dalla **stessa code-path** dei provider veri: `confirm` chiama
 * `SessionManager::confirmPayment()`, esattamente come fara' il webhook di Nexi. Se
 * funziona col bottone e non col webhook, il bug e' nel webhook — non nel dominio.
 */
final class MockController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PaymentProvider $payments,
        private readonly IdentityProvider $identities,
    ) {}

    /**
     * ✅ Simula pagamento riuscito → la sessione diventa attiva e **il vano si apre**.
     *
     * ⚠️ **Idempotente**: doppio click ⇒ **una sola** apertura. Non e' pignoleria: un
     * provider vero rimanda lo stesso webhook piu' volte, ed e' previsto dai loro contratti.
     * Se ogni consegna riaprisse il vano, il cappotto resterebbe alla merce' di chiunque.
     */
    public function confirmPayment(Payment $payment): JsonResponse
    {
        $result = $this->payments->handleCallback([
            'provider_ref' => $payment->provider_ref,
            'outcome' => 'confirmed',
        ]);

        if (! $result->isConfirmed()) {
            throw new RuntimeException('Il provider mock ha risposto in modo inatteso.');
        }

        $session = $this->sessions->confirmPayment($payment);

        return (new SessionResource($session->refresh()->load('locker')))->response();
    }

    /** ❌ Simula pagamento fallito → sessione annullata, vano di nuovo libero. */
    public function failPayment(Payment $payment): JsonResponse
    {
        $session = $this->sessions->failPayment($payment);

        return (new SessionResource($session->refresh()->load('locker')))->response();
    }

    /**
     * 🪪 Simula il tap di una carta.
     *
     * Prima volta ⇒ la carta viene **legata** alla sessione attiva del vano (e' la
     * registrazione al primo uso). Volte successive ⇒ **riapertura**.
     *
     * ⚠️ Identica alla code-path della carta NFC vera: quando arrivera' l'hardware, cambiera'
     * solo chi produce il token.
     */
    public function tapCard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cabinet_id' => ['required', 'uuid'],
            'token' => ['required', 'string', 'min:4', 'max:128'],
            'locker_number' => ['nullable', 'integer'],
        ]);

        $cabinet = Cabinet::query()->whereKey($data['cabinet_id'])->firstOrFail();
        $token = (string) $data['token'];

        // La carta e' gia' legata a una sessione attiva di questo armadio? Allora riapre.
        $session = $this->identities->resolve($token, $cabinet);

        if ($session !== null) {
            $identity = $session->identities()
                ->where('token_hash', Identity::hashToken($token))
                ->firstOrFail();

            $commandId = $this->sessions->reopen($session, $identity);

            return (new SessionResource($session->refresh()->load('locker')))
                ->additional(['action' => 'reopen', 'command_id' => $commandId])
                ->response();
        }

        // Carta sconosciuta: la si lega alla sessione attiva del vano indicato.
        $target = Session::query()
            ->where('cabinet_id', $cabinet->id)
            ->where('status', 'active')
            ->when(
                isset($data['locker_number']),
                fn ($q) => $q->whereHas('locker', fn ($l) => $l->where('number', $data['locker_number'])),
            )
            ->latest('paid_at')
            ->first();

        if ($target === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'no_active_session',
                    'message' => 'Nessuna sessione attiva a cui legare la carta in questo armadio.',
                ],
            ], JsonResponse::HTTP_CONFLICT);
        }

        $this->identities->bind($target, $token);

        return (new SessionResource($target->refresh()->load('locker')))
            ->additional(['action' => 'bound'])
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}

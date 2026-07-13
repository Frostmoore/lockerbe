<?php

namespace App\Http\Controllers;

use App\Domain\Payment\Contracts\PaymentProvider;
use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Identity;
use App\Models\Payment;
use App\Models\Session;
use App\Support\MockPanel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * LA PAGINA CHE SI APRE INQUADRANDO IL QR — sul telefono del cliente, non sul chiosco.
 *
 * ⚠️ **E' qui che si chiede l'email, e non e' un dettaglio di comodo.** Digitare un
 * indirizzo su un touchscreen, in un locale affollato e al buio, e' un modo affidabile di
 * sbagliarlo — e un'email sbagliata significa un cliente che non riceve il codice e non puo'
 * riprendersi il cappotto. Sul proprio telefono, invece, l'email e' un campo che il browser
 * riempie da solo.
 *
 * ⚠️ **Nessuna autenticazione, e non poteva essere altrimenti**: chi deposita un cappotto non
 * ha un account (§4). L'unica cosa che sta fra un estraneo e questa pagina e' il **token
 * pubblico** della sessione, che sta dentro il QR. Per questo il rate limit e' stretto: senza,
 * quel token si potrebbe cercare a forza bruta.
 *
 * ⚠️ Il tenant si ricava **dal token**, non da chi chiama: qui non c'e' nessun utente da cui
 * dedurlo, e il contesto arriverebbe in bypass — cioe' senza nessun filtro fra i clienti.
 *
 * Oggi il pagamento e' **finto** (un bottone). Il giorno che arriva Nexi (D1/F8), questa
 * pagina viene sostituita dalla loro — o la incorpora — e il resto del sistema **non cambia**:
 * la conferma passa comunque da `PaymentProvider::handleCallback()` e da
 * `SessionManager::confirmPayment()`.
 */
final class PaymentPageController extends Controller
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PaymentProvider $payments,
        private readonly TenantContext $context,
    ) {}

    public function show(string $token): View
    {
        $session = $this->sessioneDaToken($token);

        return view('pay', [
            'token' => $token,
            'session' => $session,
            'numeroVano' => $session->locker()->first()?->number,
            'mock' => MockPanel::enabled(),
        ]);
    }

    /**
     * Il cliente ha pagato (finto) e ci ha lasciato l'email.
     *
     * ⚠️ L'email si salva **prima** di confermare il pagamento: e' `confirmPayment()` a
     * generare il codice e a spedirlo, e legge l'indirizzo dalla sessione. Invertire i due
     * passaggi significa un codice generato e mandato a nessuno — cioe' un cliente che ha
     * pagato e non puo' riaprire il proprio vano.
     */
    public function pay(Request $request, string $token): RedirectResponse
    {
        $session = $this->sessioneDaToken($token);

        $dati = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        return $this->context->runForTenant($session->tenant_id, function () use ($session, $dati, $token): RedirectResponse {
            $session->forceFill(['customer_email' => (string) $dati['email']])->save();

            /** @var Payment $payment */
            $payment = $session->payment()->firstOrFail();

            $esito = $this->payments->handleCallback([
                'provider_ref' => $payment->provider_ref,
                'outcome' => 'confirmed',
            ]);

            $payment->forceFill(['payload' => $esito->payload])->save();

            $this->sessions->confirmPayment($payment);

            return redirect()->route('pay.show', ['token' => $token]);
        });
    }

    /**
     * ⚠️ Il tenant si ricava DAL TOKEN.
     *
     * Non c'e' nessun utente autenticato da cui dedurlo: senza questo, la richiesta girerebbe
     * in bypass, cioe' **senza nessun confine fra i clienti**. La ricerca si fa una volta sola,
     * scavalcando lo scope (il token e' l'unica cosa che il cliente possiede), e da li' in poi
     * si lavora dentro il suo tenant.
     */
    private function sessioneDaToken(string $token): Session
    {
        $session = Session::query()
            ->withoutGlobalScopes()
            ->where('public_token_hash', Identity::hashToken($token))
            ->first();

        if (! $session instanceof Session) {
            // ⚠️ 404, non 403: a chi bussa con un token a caso non si dice nemmeno se quel
            // token esiste.
            throw new NotFoundHttpException;
        }

        $this->context->setTenant($session->tenant_id);

        return $session;
    }
}

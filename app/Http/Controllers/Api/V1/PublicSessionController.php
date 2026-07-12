<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Session\Services\SessionManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\Identity;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Il cliente finale, quello che ha depositato il cappotto.
 *
 * ⚠️ **Non ha un account e non fa login.** Ha solo il token che gli e' stato dato quando ha
 * preso il vano (link o QR sul telefono). Chiedere a qualcuno, alle 3 di notte, di
 * ricordarsi una password per riprendersi il cappotto sarebbe una pessima idea — e infatti
 * il piano (§4) dice esplicitamente che `end_user` **non e' un account**.
 *
 * ⚠️ PROBLEMA DI TENANCY, e come lo risolviamo.
 * Queste rotte non sono autenticate, quindi `ResolveTenant` non ha nessun utente da cui
 * ricavare il tenant: la richiesta resterebbe in **bypass** — cioe' senza isolamento. Qui
 * il tenant si ricava **dal token stesso**: si cerca la sessione in bypass (e' l'unico modo
 * di trovarla), e appena la si ha si **stringe subito** il contesto sul suo tenant. Da
 * quel momento in poi vale l'isolamento come per chiunque altro.
 *
 * Il token viaggia nell'URL, quindi va trattato come una password: si cerca per **hash**,
 * non si logga mai in chiaro, e non compare in nessuna risposta.
 */
final class PublicSessionController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly TenantContext $context,
    ) {}

    /** Lo stato del proprio vano. */
    public function show(string $token): JsonResponse
    {
        $session = $this->resolveSession($token);

        return new JsonResponse([
            'data' => [
                'status' => $session->status,
                'locker_number' => $session->locker()->first()?->number,
                'amount_cents' => $session->amount_cents,
                'currency' => $session->currency,
                'expires_at' => $session->expires_at?->toIso8601String(),
                'reopen_count' => $session->reopen_count,
            ],
        ]);
    }

    /**
     * Riapre il proprio vano.
     *
     * ⚠️ Rate-limited (rotta): il token e' l'unica cosa che separa un estraneo dal cappotto
     * di qualcun altro. Senza limite, si potrebbe provare a indovinarlo a forza bruta.
     */
    public function reopen(string $token): JsonResponse
    {
        $session = $this->resolveSession($token);

        // L'identita' e' il token stesso: non c'e' una carta, c'e' il telefono del cliente.
        $identity = $session->identities()
            ->where('type', 'web_token')
            ->first()
            ?? Identity::create([
                'session_id' => $session->id,
                'type' => 'web_token',
                'token_hash' => Identity::hashToken($token),
            ]);

        $commandId = $this->sessions->reopen($session, $identity);

        return new JsonResponse([
            'data' => ['status' => $session->fresh()?->status],
            'command_id' => $commandId,
        ]);
    }

    /** Checkout dal telefono: il cliente si riprende la roba e libera il vano. */
    public function checkout(string $token): JsonResponse
    {
        $session = $this->resolveSession($token);

        $commandId = $this->sessions->checkout($session);

        return new JsonResponse([
            'data' => ['status' => $session->fresh()?->status],
            'command_id' => $commandId,
        ]);
    }

    /**
     * Trova la sessione dal token e **arma subito l'isolamento** sul suo tenant.
     *
     * 404 se il token non esiste o la sessione e' finita: identico messaggio nei due casi,
     * per non far capire a un estraneo se sta indovinando o no.
     */
    private function resolveSession(string $token): Session
    {
        $hash = Identity::hashToken($token);

        /** @var Session|null $session */
        $session = $this->context->runWithBypass(
            fn (): ?Session => Session::query()
                ->where('public_token_hash', $hash)
                ->whereIn('status', ['created', 'active'])
                ->first(),
        );

        if ($session === null) {
            throw new NotFoundHttpException('Sessione non trovata.');
        }

        // Da qui in poi questa richiesta e' dentro il tenant del cliente, come tutte.
        $this->context->setTenant($session->tenant_id);

        return $session;
    }
}

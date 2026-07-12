<?php

namespace App\Domain\Tenancy\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apre la richiesta in modalita' "sistema" (bypass), e la chiude ripulendo il contesto.
 *
 * Perche' serve. Le policy RLS sono fail-closed: senza contesto non si vede niente,
 * nemmeno la tabella `users`. Ma per autenticare qualcuno bisogna prima **trovarlo** —
 * e nel momento in cui arriva la richiesta non sappiamo ancora a che tenant appartiene.
 * E' l'uovo e la gallina: senza questa fase iniziale in bypass, nessuno potrebbe fare
 * login e nessun token Sanctum potrebbe essere risolto.
 *
 * ⚠️ La finestra in cui l'isolamento e' spento e' esattamente questa: dall'inizio della
 * richiesta fino a ResolveTenant, che scatta **subito dopo** l'autenticazione. Ogni
 * controller gira dopo ResolveTenant, quindi con l'isolamento pienamente armato.
 *
 * Quando arriveranno gli endpoint pubblici (F3: l'utente che riapre il proprio vano con
 * un token di sessione, senza account), il tenant andra' risolto **dal token** — non
 * lasciato in bypass. E' scritto qui perche' quel giorno ce ne ricordiamo.
 */
final class EstablishTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // `runWithBypass` apre in bypass e, qualunque cosa succeda, **ripristina lo stato
        // precedente**. Il ripristino conta: la connessione al database puo' essere riusata
        // dalla richiesta successiva (worker long-running, Octane), e lasciare in giro un
        // tenant impostato significherebbe farlo ereditare a qualcun altro.
        //
        // In una richiesta HTTP vera lo stato precedente e' quello vuoto (fail-closed), che
        // e' esattamente dove vogliamo tornare. In console — dove il contesto parte in
        // bypass — ripristina il bypass, invece di lasciare il processo cieco: e' cio' che
        // permette a un test di continuare a interrogare il database dopo una chiamata HTTP.
        /** @var Response */
        return $this->context->runWithBypass(fn (): Response => $next($request));
    }
}

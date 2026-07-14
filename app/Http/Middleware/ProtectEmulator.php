<?php

namespace App\Http\Middleware;

use App\Support\Bcrypt;
use App\Support\MockPanel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * IL TERZO LUCCHETTO DELL'EMULATORE.
 *
 * ⚠️ **Il doppio cancello di MockPanel non bastava, e il perche' e' una lezione.**
 *
 * `MockPanel` promette: "questa pagina non esiste in produzione". Ma la promessa e' scritta
 * come `APP_ENV !== 'production'` — e il nostro server **vero**, esposto su un dominio
 * pubblico, gira `APP_ENV=staging` con `LOCKER_MOCK_PANEL=true`. Cioe': la protezione era
 * accesa, il codice era corretto, e la pagina era **aperta a chiunque su Internet**.
 *
 * Quanto valeva quel buco:
 *   - `/emulator` elencava gli armadi di **tutti i clienti** (`runWithBypass`);
 *   - `/emulator/{id}` chiamava `activate()`, che **ruota le credenziali del device** — cioe'
 *     un estraneo che apriva quell'URL **buttava offline il chiosco vero** — e poi stampava
 *     nell'HTML la nuova password MQTT e il token API. Con quelli si sottoscrive il topic dei
 *     comandi di quell'armadio e si chiama l'API del chiosco.
 *
 * **Regola generale**: un cancello che dipende da `APP_ENV` protegge dall'ambiente, non
 * dall'attaccante. Se dietro c'e' un segreto, ci vuole un segreto.
 *
 * Questo middleware chiede una password, e:
 *   - **fail-closed**: se la password non e' configurata, la pagina **non si apre**. Una
 *     configurazione dimenticata non deve mai significare "entra pure".
 *   - vale sia per l'elenco sia per il singolo emulatore.
 */
final class ProtectEmulator
{
    /** La chiave in sessione che dice "questo browser ha gia' dato la password". */
    public const string SESSIONE = 'emulator.sbloccato';

    public function handle(Request $request, Closure $next): Response
    {
        // Il doppio cancello resta il primo: in produzione la pagina non deve esistere, punto.
        if (! MockPanel::enabled()) {
            throw new NotFoundHttpException;
        }

        if ($request->session()->get(self::SESSIONE) === true) {
            return $next($request);
        }

        return response()->view('emulator-login', [
            // ⚠️ Si dice ESPLICITAMENTE che la password non e' configurata, invece di mostrare
            //    un form che rifiuterebbe qualunque cosa: un cancello che non si apre mai e non
            //    spiega perche' e' un cancello che qualcuno, prima o poi, smonta.
            'configurata' => self::hashConfigurato() !== null,
            'errore' => null,
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * La verifica vera. Vive qui e non nel controller perche' la regola e' una sola: chi entra
     * nell'emulatore ha dato la password.
     */
    public static function password(string $tentativo): bool
    {
        return Bcrypt::check($tentativo, self::hashConfigurato());
    }

    public static function hashConfigurato(): ?string
    {
        $hash = (string) config('locker.emulator.password_hash');

        return $hash === '' ? null : $hash;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ProtectEmulator;
use App\Support\MockPanel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La porta dell'emulatore: si da' la password, si entra.
 *
 * ⚠️ Non passa da `ProtectEmulator`, ovviamente — sarebbe il cancello che protegge se stesso.
 * Ma il **primo** lucchetto (MockPanel) vale anche qui: in produzione questa rotta non esiste.
 */
final class EmulatorGateController extends Controller
{
    public function unlock(Request $request): RedirectResponse|HttpResponse
    {
        if (! MockPanel::enabled()) {
            throw new NotFoundHttpException;
        }

        $password = (string) $request->input('password', '');

        if (! ProtectEmulator::password($password)) {
            /*
             * ⚠️ Un solo messaggio per tutti i modi di sbagliare — password errata, password non
             * configurata, campo vuoto. Distinguerli direbbe a un estraneo *a che punto* e'
             * arrivato, e "la password non e' configurata" e' proprio l'informazione che non gli
             * si vuole regalare.
             */
            return response()->view('emulator-login', [
                'configurata' => ProtectEmulator::hashConfigurato() !== null,
                'errore' => 'Password errata.',
            ], HttpResponse::HTTP_UNAUTHORIZED);
        }

        // ⚠️ Rigenera l'id di sessione: senza, un id noto prima dell'autenticazione resterebbe
        //    valido dopo (session fixation).
        $request->session()->regenerate();
        $request->session()->put(ProtectEmulator::SESSIONE, true);

        $destinazione = (string) $request->input('destinazione', '');

        // Solo percorsi interni all'emulatore: un `destinazione` arbitrario sarebbe un open redirect.
        $sicura = str_starts_with($destinazione, '/emulator');

        return redirect()->to($sicura ? $destinazione : '/emulator');
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget(ProtectEmulator::SESSIONE);

        return redirect()->to('/emulator');
    }
}

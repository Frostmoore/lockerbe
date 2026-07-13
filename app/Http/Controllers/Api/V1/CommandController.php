<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Services\CommandIssuer;
use App\Http\Resources\CommandResource;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Locker;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'apertura dei vani. **La superficie piu' pericolosa del sistema.**
 */
final class CommandController
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CommandIssuer $commands,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * 🔓 Apre un vano.
     *
     * ⚠️ **Asincrono ma tracciabile.** Risponde **202**, non 200: il 202 non vuol dire "il vano
     * e' aperto", vuol dire "ho preso in carico l'ordine". L'esito reale arriva con l'ack del
     * device (`GET /commands/{id}`). Chiunque legga questa risposta come una conferma di
     * apertura si sta illudendo — e in un sistema di armadietti l'illusione costa un cappotto.
     *
     * ⚠️ **409 se l'armadio e' offline**, e nessun comando viene creato. Non si accoda una
     * promessa di apertura: verrebbe consegnata ore dopo, aprendo un vano pieno di roba davanti
     * a nessuno.
     *
     * ⚠️ Richiede l'header **`Idempotency-Key`** (uuid): diventa la PK del comando, e un retry
     * con la stessa chiave restituisce lo stesso comando invece di aprire il vano due volte.
     */
    public function open(Request $request, Locker $locker): JsonResponse
    {
        $this->authorize('open', $locker);

        $commandId = $this->commands->issueOpen(
            $locker,
            'admin',
            $this->idempotencyKey($request),
        );

        return (new CommandResource(Command::query()->findOrFail($commandId)))
            ->additional([
                'message' => 'Comando preso in carico. L\'esito reale arriva con l\'ack del device: '
                    .'interroga GET /commands/'.$commandId,
            ])
            ->response()
            ->setStatusCode(JsonResponse::HTTP_ACCEPTED);
    }

    /** Lo stato reale di un comando: `pending` · `sent` · `acked` · `expired` · `failed`. */
    public function show(Command $command): CommandResource
    {
        $this->authorize('viewAny', Locker::class);

        return new CommandResource($command);
    }

    /**
     * ⚠️⚠️ **APRE TUTTI I VANI DELL'ARMADIO IN UN COLPO SOLO.**
     *
     * E' l'azione piu' pericolosa che questo sistema sappia fare: **svuota il guardaroba**.
     * Serve nelle emergenze (allarme antincendio, fine serata con clienti che non tornano), e
     * proprio per questo:
     *
     *   - non e' di `tenant_staff`: resta al gestore, che ne risponde (`locker.open_all`);
     *   - richiede una **conferma esplicita** (`confirm: true`) e una **motivazione scritta**:
     *     non si arriva qui per sbaglio cliccando in giro;
     *   - finisce nell'audit con nome, cognome e motivo.
     *
     * ⚠️ I vani `out_of_service` restano chiusi: sono guasti, aprirli non ha senso.
     */
    public function openAll(Request $request, Cabinet $cabinet): JsonResponse
    {
        $this->authorize('openAll', $cabinet);

        $data = $request->validate([
            'confirm' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $lockers = $cabinet->lockers()
            ->whereNot('status', 'out_of_service')
            ->orderBy('number')
            ->get();

        /** @var User $user */
        $user = $request->user();

        $this->audit->log('cabinet.open_all', [
            'cabinet_id' => $cabinet->id,
            'actor' => $user,
            'context' => [
                'reason' => (string) $data['reason'],
                'vani' => $lockers->count(),
            ],
        ]);

        $comandi = [];

        foreach ($lockers as $locker) {
            // Ogni vano ha il suo comando, con il suo TTL e la sua firma. Un unico "comando
            // gigante" sarebbe impossibile da tracciare e da far scadere per parti.
            $comandi[] = $this->commands->issueOpen($locker, 'admin');
        }

        return new JsonResponse([
            'command_ids' => $comandi,
            'lockers' => $lockers->count(),
            'message' => 'Apertura di massa presa in carico. Ogni vano ha il suo comando tracciabile.',
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * La chiave di idempotenza. **Obbligatoria, e non la genera il server.**
     *
     * ⚠️ Generarla qui vanificherebbe tutto: due retry dello stesso client produrrebbero due
     * chiavi diverse, quindi due comandi, quindi **due aperture**. Deve nascere dal client, che
     * e' l'unico a sapere che sta ritentando la *stessa* richiesta e non facendone una nuova.
     */
    private function idempotencyKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');

        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => [
                    'Header Idempotency-Key mancante o non valido (serve un uuid). '
                    .'Senza, un retry di rete aprirebbe il vano due volte.',
                ],
            ]);
        }

        return $key;
    }
}

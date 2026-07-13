<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Exceptions\DeviceOfflineException;
use App\Domain\Command\Services\CommandIssuer;
use App\Filament\Resources\CabinetResource;
use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Locker;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * L'ARMADIO VISTO COME UNA MACCHINA: il chiosco al centro, i vani attaccati.
 *
 * ⚠️ È il posto in cui si viene quando qualcosa non va, e deve dire la verità **adesso**:
 * per questo la pagina si aggiorna da sola ogni 5 secondi. Uno stato dei vani fermo è uno
 * stato *sbagliato con sicurezza* — chi vede "libero" un vano occupato tre secondi fa ci
 * mette dentro un secondo cappotto.
 *
 * ⚠️ Qui sotto ci sono anche gli ultimi **comandi**: gli ordini di apertura mandati al
 * chiosco. Non sono una voce di menu perché nessuno si sveglia la mattina volendo "vedere i
 * comandi" — li si guarda quando un vano non si è aperto, e allora si è già dentro questa
 * pagina, davanti a quel vano.
 */
class NodiCabinet extends Page
{
    /*
     * ⚠️ Il trait di Filament, NON una proprietà pubblica nostra.
     *
     * `$record` deve poter contenere una **stringa**: Livewire, a ogni re-render, riassegna i
     * parametri alle proprietà pubbliche — e a quel punto ci ributta dentro l'id, non il
     * modello. Dichiararla `public Cabinet $record` compila, funziona al primo caricamento, e
     * poi esplode al primo click. Il trait la tiene `Model|int|string` apposta.
     */
    use InteractsWithRecord;

    protected static string $resource = CabinetResource::class;

    protected string $view = 'filament.resources.cabinet.nodi';

    public function mount(string|int $record): void
    {
        $this->record = $this->resolveRecord($record);

        // ⚠️ La policy, non solo il menu: chi non può vedere questo armadio non deve poter
        // arrivare qui digitando l'indirizzo a mano.
        abort_unless(auth()->user()?->can('view', $this->armadio()) ?? false, 403);
    }

    /** L'armadio, già tipizzato: `$record`, per Livewire, può essere una stringa. */
    public function armadio(): Cabinet
    {
        /** @var Cabinet */
        return $this->getRecord();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->armadio()->code;
    }

    /** @return Collection<int, Locker> */
    public function vani(): Collection
    {
        return $this->armadio()->lockers()
            ->with('cabinet')
            ->orderBy('number')
            ->get();
    }

    /**
     * Gli ultimi ordini di apertura mandati a questo armadio.
     *
     * @return Collection<int, Command>
     */
    public function comandi(): Collection
    {
        return $this->armadio()->commands()
            ->with('locker')
            ->orderByDesc('issued_at')
            ->limit(15)
            ->get();
    }

    /**
     * ⚠️ APRIRE UN VANO. Il pannello è solo il grilletto: le sicure stanno in `CommandIssuer`
     * (armadio offline ⇒ nessun comando · scadenza 30s · idempotenza).
     */
    public function apri(string $lockerId): void
    {
        $vano = $this->armadio()->lockers()->findOrFail($lockerId);

        if (! (auth()->user()?->can('open', $vano) ?? false)) {
            abort(403);
        }

        try {
            app(CommandIssuer::class)->issueOpen($vano, 'admin', (string) Str::uuid7());
        } catch (DeviceOfflineException) {
            // ⚠️ Non è un guasto del pannello: è la difesa più importante del sistema che fa
            // il suo mestiere. L'operatore deve capirlo, non pensare a un bug.
            Notification::make()
                ->title('Armadio non raggiungibile.')
                ->body('Nessun comando è stato creato: un\'apertura accodata verrebbe consegnata chissà quando — magari alle 4 del mattino, su un vano pieno.')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title("Apertura del vano {$vano->number} presa in carico.")
            ->body('Il comando è firmato e scade tra pochi secondi. Guarda qui sotto per l\'esito.')
            ->success()
            ->send();
    }

    /** La serratura è rotta: il vano smette di essere assegnato. */
    public function fuoriServizio(string $lockerId): void
    {
        $vano = $this->armadio()->lockers()->findOrFail($lockerId);

        if (! (auth()->user()?->can('service', $vano) ?? false)) {
            abort(403);
        }

        // ⚠️ Solo un vano libero. Dentro un vano occupato c'è la roba di qualcuno, e un vano
        // fuori servizio non viene più riaperto dal flusso normale: il cliente tornerebbe e
        // non riuscirebbe a riprendersi il cappotto.
        if ($vano->status !== 'free') {
            Notification::make()
                ->title('Solo un vano libero può andare fuori servizio.')
                ->body('Dentro questo c\'è la roba di qualcuno: prima va riconsegnata.')
                ->warning()
                ->send();

            return;
        }

        /** @var User $attore */
        $attore = auth()->user();

        $vano->forceFill(['status' => 'out_of_service'])->save();

        app(AuditLogger::class)->log('locker.out_of_service', [
            'cabinet_id' => $this->armadio()->id,
            'locker_id' => $vano->id,
            'actor' => $attore,
            'context' => ['reason' => 'dalla vista a nodi'],
        ]);

        Notification::make()->title("Vano {$vano->number} fuori servizio.")->warning()->send();
    }

    public function rimettiInServizio(string $lockerId): void
    {
        $vano = $this->armadio()->lockers()->findOrFail($lockerId);

        if (! (auth()->user()?->can('service', $vano) ?? false)) {
            abort(403);
        }

        /** @var User $attore */
        $attore = auth()->user();

        // ⚠️ Torna **assegnabile**: se dentro c'è ancora qualcosa, il prossimo cliente ci
        // troverà la roba di un altro. Il sistema non può saperlo — il mondo fisico sì.
        $vano->forceFill(['status' => 'free', 'current_session_id' => null])->save();

        app(AuditLogger::class)->log('locker.in_service', [
            'cabinet_id' => $this->armadio()->id,
            'locker_id' => $vano->id,
            'actor' => $attore,
        ]);

        Notification::make()->title("Vano {$vano->number} di nuovo in servizio.")->success()->send();
    }
}

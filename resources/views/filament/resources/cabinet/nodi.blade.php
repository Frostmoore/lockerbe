{{-- ⚠️ 5 secondi. È il posto in cui si viene quando qualcosa non va: deve dire la verità ADESSO. --}}
<x-filament-panels::page wire:poll.5s>
    @php($armadio = $this->armadio())
    @php($chiosco = $armadio->device)
    @php($vani = $this->vani())
    @php($online = $armadio->isOnline())

    {{-- ═══ IL NODO CHIOSCO: il FCV5003 avvitato in mezzo alla lamiera ═══ --}}
    <div class="flex flex-col items-center">
        <div @class([
            'w-full max-w-md rounded-xl border-2 p-4 text-center',
            'border-success-500 bg-success-50 dark:bg-success-400/10' => $online,
            'border-gray-300 bg-gray-50 dark:border-white/10 dark:bg-white/5' => ! $online,
        ])>
            <div class="flex items-center justify-center gap-2">
                <span @class([
                    'h-3 w-3 rounded-full',
                    'animate-pulse bg-success-500 shadow-[0_0_10px] shadow-success-500' => $online,
                    'bg-gray-400' => ! $online,
                ])></span>
                <span class="font-semibold text-gray-950 dark:text-white">
                    {{ $chiosco?->serial ?? 'nessun chiosco' }}
                </span>
            </div>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                @if (! $chiosco)
                    {{-- ⚠️ Un armadio senza chiosco è lamiera: non apre niente. --}}
                    Questo armadio non ha un chiosco: non può aprire niente.
                @elseif ($online)
                    Ultimo battito {{ $armadio->last_seen_at?->diffForHumans() ?? '—' }}
                @else
                    {{-- ⚠️ Offline non è un dettaglio: nessun vano si aprirà, e ogni apertura
                         risponderà 409. È la difesa contro il rischio #1, non un guasto. --}}
                    <span class="font-medium text-danger-600 dark:text-danger-400">
                        Non raggiungibile.
                    </span>
                    Nessun vano si aprirà finché non torna. Ultimo battito:
                    {{ $armadio->last_seen_at?->diffForHumans() ?? 'mai' }}
                @endif
            </p>
        </div>

        {{-- Il filo che scende dal chiosco ai vani: è un bus RS-485, non una metafora. --}}
        <div @class([
            'h-8 w-0.5',
            'bg-success-500' => $online,
            'bg-gray-300 dark:bg-white/10' => ! $online,
        ])></div>
    </div>

    {{-- ═══ I NODI VANO ═══ --}}
    <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
        @foreach ($vani as $vano)
            @php($stile = [
                'free'           => ['b' => 'border-success-500', 'g' => 'bg-success-50 dark:bg-success-400/10', 't' => 'text-success-700 dark:text-success-400', 'l' => 'libero'],
                'reserved'       => ['b' => 'border-warning-500', 'g' => 'bg-warning-50 dark:bg-warning-400/10', 't' => 'text-warning-700 dark:text-warning-400', 'l' => 'prenotato'],
                'occupied'       => ['b' => 'border-info-500',    'g' => 'bg-info-50 dark:bg-info-400/10',       't' => 'text-info-700 dark:text-info-400',       'l' => 'occupato'],
                'checkout'       => ['b' => 'border-warning-500', 'g' => 'bg-warning-50 dark:bg-warning-400/10', 't' => 'text-warning-700 dark:text-warning-400', 'l' => 'riconsegna'],
                'out_of_service' => ['b' => 'border-danger-500',  'g' => 'bg-danger-50 dark:bg-danger-400/10',   't' => 'text-danger-700 dark:text-danger-400',   'l' => 'rotto'],
            ][$vano->status] ?? ['b' => 'border-gray-300', 'g' => '', 't' => 'text-gray-500', 'l' => $vano->status])

            <div class="flex flex-col items-center">
                {{-- il ramo che lo attacca al bus --}}
                <div class="h-3 w-0.5 bg-gray-300 dark:bg-white/10"></div>

                <div @class(['w-full rounded-lg border-2 p-2 text-center', $stile['b'], $stile['g']])>
                    <div class="text-xl font-bold text-gray-950 dark:text-white">{{ $vano->number }}</div>
                    <div @class(['text-[11px] font-medium', $stile['t']])>{{ $stile['l'] }}</div>

                    {{-- La mappa fisica: quale scheda, quale canale. È ciò che il chiosco
                         usa davvero per far scattare QUELLA serratura. --}}
                    <div class="mt-0.5 font-mono text-[10px] text-gray-400">
                        {{ $vano->board_address }}·{{ $vano->channel }}
                    </div>

                    <div class="mt-2 flex flex-col gap-1">
                        @if ($vano->status !== 'out_of_service')
                            <button type="button"
                                    wire:click="apri('{{ $vano->id }}')"
                                    wire:confirm="Aprire il vano {{ $vano->number }}? Lo sportello si apre davvero: se dentro c'è la roba di un cliente, resta accessibile."
                                    class="rounded bg-warning-500 px-1.5 py-1 text-[11px] font-medium text-white hover:bg-warning-600">
                                Apri
                            </button>
                        @endif

                        @if ($vano->status === 'free')
                            <button type="button"
                                    wire:click="fuoriServizio('{{ $vano->id }}')"
                                    wire:confirm="Mettere il vano {{ $vano->number }} fuori servizio? Smetterà di essere assegnato ai clienti."
                                    class="rounded border border-danger-300 px-1.5 py-1 text-[11px] text-danger-600 hover:bg-danger-50 dark:border-danger-400/30 dark:text-danger-400 dark:hover:bg-danger-400/10">
                                Guasto
                            </button>
                        @elseif ($vano->status === 'out_of_service')
                            <button type="button"
                                    wire:click="rimettiInServizio('{{ $vano->id }}')"
                                    wire:confirm="Rimettere in servizio il vano {{ $vano->number }}? Assicurati che sia VUOTO: da adesso torna assegnabile, e il prossimo cliente ci troverà quello che c'è rimasto."
                                    class="rounded border border-success-300 px-1.5 py-1 text-[11px] text-success-600 hover:bg-success-50 dark:border-success-400/30 dark:text-success-400 dark:hover:bg-success-400/10">
                                Ripara
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ═══ GLI ORDINI DI APERTURA (i "comandi") ═══ --}}
    <x-filament::section collapsible>
        <x-slot name="heading">Ultimi ordini di apertura</x-slot>

        <x-slot name="description">
            Il server non apre niente da solo: crea un ordine <strong>firmato</strong>, che
            <strong>scade in 30 secondi</strong>, e lo manda al chiosco. Questa lista risponde a una
            domanda sola: <em>quel vano si è aperto, sì o no?</em>
        </x-slot>

        @forelse ($this->comandi() as $comando)
            @php($esito = [
                'acked'   => ['t' => 'text-success-600 dark:text-success-400', 'l' => 'eseguito'],
                'sent'    => ['t' => 'text-info-600 dark:text-info-400',       'l' => 'consegnato'],
                'pending' => ['t' => 'text-warning-600 dark:text-warning-400', 'l' => 'in coda'],
                'expired' => ['t' => 'text-danger-600 dark:text-danger-400',   'l' => 'scaduto'],
                'failed'  => ['t' => 'text-danger-600 dark:text-danger-400',   'l' => 'fallito'],
            ][$comando->status] ?? ['t' => 'text-gray-500', 'l' => $comando->status])

            <div class="flex items-baseline gap-3 border-b border-gray-100 py-1.5 font-mono text-xs last:border-0 dark:border-white/5">
                <span class="text-gray-400">{{ $comando->issued_at->format('d/m H:i:s') }}</span>
                <span class="text-gray-950 dark:text-white">vano {{ $comando->locker?->number ?? '—' }}</span>
                <span @class(['font-semibold', $esito['t']])>{{ $esito['l'] }}</span>

                @if ($comando->status === 'pending' && $comando->isExpired())
                    {{-- ⚠️ `pending` a lungo non è "sta arrivando": è il segno che nessuno sta
                         pubblicando. Quasi sempre vuol dire che `queue:work` non gira. --}}
                    <span class="text-danger-500">scaduto in coda — nessuno lo ha pubblicato</span>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Nessun ordine di apertura, ancora.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

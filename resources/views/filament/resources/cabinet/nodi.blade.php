{{-- ⚠️ 5 secondi. È il posto in cui si viene quando qualcosa non va: deve dire la verità ADESSO. --}}
<x-filament-panels::page wire:poll.5s>
    @php($armadio = $this->armadio())
    @php($chiosco = $armadio->device)
    @php($vani = $this->vani())
    @php($online = $armadio->isOnline())

    {{-- ═══ IL NODO CHIOSCO: il FCV5003 avvitato in mezzo alla lamiera ═══ --}}
    <div class="lk-hub">
        <div @class(['lk-hub__box', 'lk-hub__box--online' => $online])>
            <div class="lk-hub__seriale">
                <span @class(['lk-dot', 'lk-dot--on' => $online])></span>
                {{ $chiosco?->serial ?? 'nessun chiosco' }}
            </div>

            <p class="lk-hub__stato">
                @if (! $chiosco)
                    {{-- ⚠️ Un armadio senza chiosco è lamiera: non apre niente. --}}
                    <b>Questo armadio non ha un chiosco.</b> Non può aprire niente.
                @elseif ($online)
                    Ultimo battito {{ $armadio->last_seen_at?->diffForHumans() ?? '—' }}
                @else
                    {{-- ⚠️ Offline non è un dettaglio: è la difesa contro il rischio #1 che fa il
                         suo mestiere. Ogni apertura risponderà 409, e nessun comando verrà creato. --}}
                    <b>Non raggiungibile.</b>
                    Nessun vano si aprirà finché non torna. Ultimo battito:
                    {{ $armadio->last_seen_at?->diffForHumans() ?? 'mai' }}
                @endif
            </p>
        </div>

        {{-- Il filo che scende dal chiosco ai vani: è un bus RS-485, non una metafora. --}}
        <div @class(['lk-bus', 'lk-bus--online' => $online])></div>
        <div @class(['lk-bus__linea', 'lk-bus__linea--online' => $online])></div>
    </div>

    {{-- ═══ I NODI VANO ═══ --}}
    <div class="lk-vani">
        @foreach ($vani as $vano)
            @php($etichetta = [
                'free' => 'libero',
                'reserved' => 'prenotato',
                'occupied' => 'occupato',
                'checkout' => 'riconsegna',
                'out_of_service' => 'rotto',
            ][$vano->status] ?? $vano->status)

            <div class="lk-vano">
                <div class="lk-vano__ramo"></div>

                <div class="lk-vano__box lk-vano__box--{{ $vano->status }}">
                    <div class="lk-vano__numero">{{ $vano->number }}</div>
                    <div class="lk-vano__stato">{{ $etichetta }}</div>

                    {{-- La mappa fisica: quale scheda, quale canale. È ciò che il chiosco usa
                         davvero per far scattare QUELLA serratura. --}}
                    <div class="lk-vano__mappa">{{ $vano->board_address }}·{{ $vano->channel }}</div>

                    <div class="lk-vano__azioni">
                        @if ($vano->status !== 'out_of_service')
                            <button type="button"
                                    class="lk-btn lk-btn--apri"
                                    wire:click="apri('{{ $vano->id }}')"
                                    wire:confirm="Aprire il vano {{ $vano->number }}? Lo sportello si apre davvero: se dentro c'è la roba di un cliente, resta accessibile.">
                                Apri
                            </button>
                        @endif

                        @if ($vano->status === 'free')
                            <button type="button"
                                    class="lk-btn lk-btn--guasto"
                                    wire:click="fuoriServizio('{{ $vano->id }}')"
                                    wire:confirm="Mettere il vano {{ $vano->number }} fuori servizio? Smetterà di essere assegnato ai clienti.">
                                Guasto
                            </button>
                        @elseif ($vano->status === 'out_of_service')
                            <button type="button"
                                    class="lk-btn lk-btn--ripara"
                                    wire:click="rimettiInServizio('{{ $vano->id }}')"
                                    wire:confirm="Rimettere in servizio il vano {{ $vano->number }}? Assicurati che sia VUOTO: da adesso torna assegnabile, e il prossimo cliente ci troverà quello che c'è rimasto.">
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
                'acked' => 'eseguito',
                'sent' => 'consegnato',
                'pending' => 'in coda',
                'expired' => 'scaduto',
                'failed' => 'fallito',
            ][$comando->status] ?? $comando->status)

            <div class="lk-cmd">
                <span class="lk-cmd__ora">{{ $comando->issued_at->format('d/m H:i:s') }}</span>
                <span class="lk-cmd__vano">vano {{ $comando->locker?->number ?? '—' }}</span>
                <span class="lk-cmd__esito lk-cmd__esito--{{ $comando->status }}">{{ $esito }}</span>

                <span class="lk-cmd__nota">
                    @if ($comando->status === 'pending' && $comando->isExpired())
                        {{-- ⚠️ `pending` a lungo non è "sta arrivando": è il segno che nessuno sta
                             pubblicando. Quasi sempre vuol dire che `queue:work` non gira. --}}
                        scaduto in coda — nessuno lo ha pubblicato (queue:work non gira?)
                    @endif
                </span>
            </div>
        @empty
            <p class="lk-mono">Nessun ordine di apertura, ancora.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

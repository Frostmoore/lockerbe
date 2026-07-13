<x-filament-panels::page>
    <div class="lk-filtri">
        <input type="search"
               class="lk-input"
               wire:model.live.debounce.400ms="cerca"
               placeholder="Cerca: azione, utente, IP, motivo…">

        <select class="lk-select" wire:model.live="soloErrori">
            <option value="">Tutto</option>
            <option value="fail">Solo ciò che è andato storto</option>
        </select>
    </div>

    {{-- ⚠️ UN LOG, NON UNA TABELLA.
         Colonne allineate: un log in cui i campi ballano da riga a riga non si scorre con
         l'occhio — si legge una riga alla volta, cioè non si legge.
         E gli uuid sono già risolti in cose: "019f5c…" è diventato "vano 3 · armadio G1". --}}
    <div class="lk-log">
        @forelse ($this->righe() as $r)
            <div @class(['lk-log__riga', 'lk-log__riga--fail' => ! $r['ok']])>
                <span class="lk-log__ora">{{ $r['quando']?->format('d/m H:i:s') ?? '—' }}</span>

                <span @class(['lk-log__esito', 'lk-log__esito--ok' => $r['ok'], 'lk-log__esito--fail' => ! $r['ok']])>
                    {{ $r['ok'] ? 'ok' : 'FAIL' }}
                </span>

                <span class="lk-log__chi">{{ $r['chi'] }}</span>

                <span>
                    <span @class(['lk-log__cosa', 'lk-log__cosa--grave' => $r['grave']])>{{ $r['frase'] }}</span>

                    @if ($r['dove'])
                        <span class="lk-log__dove">{{ $r['dove'] }}</span>
                    @endif

                    @if ($r['errore'])
                        <span class="lk-log__errore">[{{ $r['errore'] }}]</span>
                    @endif

                    {{-- ⚠️ È qui che vive la MOTIVAZIONE di un "apri tutti". --}}
                    @foreach ($r['contesto'] as $chiave => $valore)
                        <span class="lk-log__ctx">{{ $chiave }}=<b>{{ $valore }}</b></span>
                    @endforeach

                    <span class="lk-log__hash">#{{ $r['hash'] }}</span>

                    @if ($r['ip'])
                        <span class="lk-log__ip">{{ $r['ip'] }}</span>
                    @endif
                </span>
            </div>
        @empty
            <div class="lk-log__vuoto">Il registro è vuoto — o il filtro non trova niente.</div>
        @endforelse
    </div>

    <div class="lk-piede">
        <span>Ultime {{ $this->quante }} voci, dalla più recente.</span>

        <x-filament::button wire:click="diPiu" color="gray" size="sm">
            Mostrane altre 100
        </x-filament::button>
    </div>

    {{-- ⚠️ Non è una nota di colore: è il motivo per cui questo registro vale qualcosa. --}}
    <p class="lk-piede">
        Il registro è <strong>append-only, imposto dal database</strong>: al ruolo con cui gira
        l'applicazione sono stati revocati UPDATE e DELETE, e ogni riga porta l'hash della
        precedente. Nessuno può riscrivere la storia — nemmeno noi.
    </p>
</x-filament-panels::page>

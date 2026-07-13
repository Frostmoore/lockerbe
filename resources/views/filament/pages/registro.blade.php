<x-filament-panels::page>
    {{-- I filtri: pochi, e quelli che servono davvero quando qualcosa è andato storto. --}}
    <div class="flex flex-wrap items-center gap-3">
        <input type="search"
               wire:model.live.debounce.400ms="cerca"
               placeholder="Cerca: azione, utente, IP, motivo…"
               class="w-72 rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900">

        <select wire:model.live="soloErrori"
                class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900">
            <option value="">Tutto</option>
            <option value="fail">Solo ciò che è andato storto</option>
        </select>
    </div>

    {{-- ⚠️ Un log, non una tabella: una riga = una frase.
         Gli uuid sono già stati risolti in cose ("vano 3 · armadio GUARDAROBA-1"): un
         registro fatto di uuid non lo rilegge nessuno, nemmeno chi l'ha scritto. --}}
    <div class="overflow-x-auto rounded-xl bg-gray-950 p-4 font-mono text-[13px] leading-relaxed text-gray-300 ring-1 ring-white/10">
        @forelse ($this->righe() as $r)
            <div class="whitespace-nowrap py-0.5 hover:bg-white/5">
                {{-- quando --}}
                <span class="text-gray-500">{{ $r['quando']?->format('Y-m-d H:i:s') ?? '—' }}</span>

                {{-- esito: verde o rosso, ed è la prima cosa che l'occhio trova --}}
                @if ($r['ok'])
                    <span class="text-emerald-400">ok  </span>
                @else
                    <span class="font-bold text-red-400">FAIL</span>
                @endif

                {{-- chi --}}
                <span class="text-amber-300">{{ $r['chi'] }}</span>

                {{-- cosa: le azioni pericolose gridano --}}
                <span @class([
                    'text-sky-300' => ! str_contains($r['frase'], '⚠️'),
                    'font-bold text-red-300' => str_contains($r['frase'], '⚠️'),
                ])>{{ $r['frase'] }}</span>

                {{-- dove --}}
                @if ($r['dove'])
                    <span class="text-violet-300">{{ $r['dove'] }}</span>
                @endif

                {{-- perché è fallito --}}
                @if ($r['errore'])
                    <span class="text-red-400">[{{ $r['errore'] }}]</span>
                @endif

                {{-- il contesto: è qui che vive la MOTIVAZIONE di un "apri tutti" --}}
                @if ($r['contesto'])
                    <span class="text-gray-400">{{ $r['contesto'] }}</span>
                @endif

                {{-- l'anello della catena: cancellare una riga in mezzo si vede --}}
                <span class="text-gray-700">#{{ $r['hash'] }}</span>

                @if ($r['ip'])
                    <span class="text-gray-600">{{ $r['ip'] }}</span>
                @endif
            </div>
        @empty
            <div class="py-6 text-center text-gray-500">Il registro è vuoto — o il filtro non trova niente.</div>
        @endforelse
    </div>

    <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
        <span>Ultime {{ $this->quante }} voci, dalla più recente.</span>

        <x-filament::button wire:click="diPiu" color="gray" size="sm">
            Mostrane altre 100
        </x-filament::button>
    </div>

    {{-- ⚠️ Non è una nota di colore: è il motivo per cui questo registro vale qualcosa. --}}
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Il registro è <strong>append-only, imposto dal database</strong>: al ruolo con cui gira
        l'applicazione sono stati revocati UPDATE e DELETE, e ogni riga porta l'hash della
        precedente. Nessuno può riscrivere la storia — nemmeno noi.
    </p>
</x-filament-panels::page>

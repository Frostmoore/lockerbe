<x-filament-panels::page wire:poll.15s>
    @php($armadi = $this->armadi())
    @php($chioschi = $this->chioschi())

    {{-- ═══ GLI ARMADI ═══ --}}
    <x-filament::section>
        <x-slot name="heading">Armadi ({{ $armadi->count() }})</x-slot>

        @forelse ($armadi as $armadio)
            @php($online = $armadio->isOnline())

            <a href="{{ $this->urlNodi($armadio) }}"
               class="flex items-center gap-4 border-b border-gray-100 py-3 last:border-0 hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">

                <span @class([
                    'h-2.5 w-2.5 shrink-0 rounded-full',
                    'bg-success-500' => $online,
                    'bg-gray-400' => ! $online,
                ])></span>

                <span class="w-40 shrink-0 font-medium text-gray-950 dark:text-white">{{ $armadio->code }}</span>
                <span class="flex-1 truncate text-sm text-gray-500 dark:text-gray-400">{{ $armadio->name }}</span>

                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $armadio->lockers_liberi_count }}/{{ $armadio->lockers_count }} liberi
                </span>

                @if ($armadio->lockers_rotti_count > 0)
                    <span class="text-sm font-medium text-danger-600 dark:text-danger-400">
                        {{ $armadio->lockers_rotti_count }} rotti
                    </span>
                @endif

                <span class="w-40 shrink-0 text-right font-mono text-xs text-gray-400">
                    {{-- ⚠️ Un armadio senza chiosco è lamiera: non apre niente. --}}
                    {{ $armadio->device?->serial ?? 'nessun chiosco' }}
                </span>
            </a>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Questo locale non ha ancora armadi.</p>
        @endforelse
    </x-filament::section>

    {{-- ═══ I CHIOSCHI ═══ --}}
    <x-filament::section>
        <x-slot name="heading">Chioschi ({{ $chioschi->count() }})</x-slot>

        <x-slot name="description">
            ⚠️ Ci sono <strong>tutti</strong>, anche quelli registrati e non ancora legati a un armadio:
            è proprio quello che il tecnico ha appena messo dentro e sta cercando.
        </x-slot>

        @forelse ($chioschi as $chiosco)
            <div class="flex items-center gap-4 border-b border-gray-100 py-3 last:border-0 dark:border-white/5">
                <span class="w-48 shrink-0 font-mono text-sm text-gray-950 dark:text-white">{{ $chiosco->serial }}</span>

                <span @class([
                    'w-40 shrink-0 text-sm font-medium',
                    'text-success-600 dark:text-success-400' => $chiosco->status === 'active',
                    'text-warning-600 dark:text-warning-400' => $chiosco->status === 'registered',
                    'text-danger-600 dark:text-danger-400' => $chiosco->status === 'revoked',
                ])>
                    {{ ['registered' => 'mai attivato', 'active' => 'attivo', 'revoked' => 'revocato'][$chiosco->status] ?? $chiosco->status }}
                </span>

                <span class="flex-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $chiosco->cabinet?->code ?? '— non associato a nessun armadio —' }}
                </span>

                <span class="text-sm text-gray-400">
                    {{ $chiosco->last_seen_at?->diffForHumans() ?? 'mai visto' }}
                </span>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Questo locale non ha ancora chioschi.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

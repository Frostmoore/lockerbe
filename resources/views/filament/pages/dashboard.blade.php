{{-- ⚠️ Polling: uno stato degli armadi fermo è uno stato sbagliato con sicurezza. --}}
<x-filament-panels::page wire:poll.10s>
    @php($locali = $this->locali())

    @forelse ($locali as $nomeLocale => $armadi)
        <section class="mb-8">
            <h2 class="mb-3 flex items-center gap-2 text-lg font-semibold text-gray-950 dark:text-white">
                {{ $nomeLocale }}
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-normal text-gray-600 dark:bg-white/10 dark:text-gray-400">
                    {{ $armadi->count() }} {{ $armadi->count() === 1 ? 'armadio' : 'armadi' }}
                </span>
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($armadi as $armadio)
                    @php($online = $armadio->isOnline())
                    @php($chiosco = $armadio->device)

                    <a href="{{ $this->urlNodi($armadio) }}"
                       class="group block rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-primary-500 hover:shadow-md dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500">

                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    {{-- ⚠️ Il pallino è la cosa più grande del cartellino: se l'armadio
                                         è offline, oggi quel guardaroba non apre. --}}
                                    <span @class([
                                        'h-2.5 w-2.5 shrink-0 rounded-full',
                                        'bg-success-500 shadow-[0_0_8px] shadow-success-500/60' => $online,
                                        'bg-gray-400' => ! $online,
                                    ])></span>

                                    <span class="truncate font-semibold text-gray-950 dark:text-white">
                                        {{ $armadio->code }}
                                    </span>
                                </div>

                                <p class="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">
                                    {{ $armadio->name }}
                                </p>
                            </div>

                            <span @class([
                                'shrink-0 rounded-md px-2 py-1 text-xs font-medium',
                                'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $online,
                                'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400' => ! $online,
                            ])>
                                {{ $online ? 'online' : 'offline' }}
                            </span>
                        </div>

                        {{-- I vani: la riga che dice se c'è posto. --}}
                        <div class="mt-4 flex items-center gap-4 text-sm">
                            <span class="font-medium text-success-600 dark:text-success-400">
                                {{ $armadio->lockers_liberi_count }} liberi
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $armadio->lockers_occupati_count }} occupati
                            </span>
                            @if ($armadio->lockers_rotti_count > 0)
                                <span class="font-medium text-danger-600 dark:text-danger-400">
                                    {{ $armadio->lockers_rotti_count }} rotti
                                </span>
                            @endif
                        </div>

                        {{-- Il chiosco: è avvitato dentro questo armadio. --}}
                        <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-3 text-xs dark:border-white/5">
                            @if ($chiosco)
                                <span class="font-mono text-gray-500 dark:text-gray-400">{{ $chiosco->serial }}</span>
                                <span @class([
                                    'font-medium',
                                    'text-success-600 dark:text-success-400' => $chiosco->status === 'active',
                                    'text-warning-600 dark:text-warning-400' => $chiosco->status === 'registered',
                                    'text-danger-600 dark:text-danger-400' => $chiosco->status === 'revoked',
                                ])>
                                    {{ ['registered' => 'mai attivato', 'active' => 'attivo', 'revoked' => 'revocato'][$chiosco->status] ?? $chiosco->status }}
                                </span>
                            @else
                                {{-- ⚠️ Un armadio senza chiosco non apre niente: è lamiera. --}}
                                <span class="font-medium text-danger-600 dark:text-danger-400">
                                    nessun chiosco — questo armadio non apre
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/10">
            <p class="font-medium text-gray-950 dark:text-white">Nessun armadio.</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Registra un armadio, poi il chiosco che ci sta dentro, e premi <em>Attiva</em>.
            </p>
        </div>
    @endforelse
</x-filament-panels::page>

<x-filament-panels::page wire:poll.15s>
    @php($armadi = $this->armadi())
    @php($chioschi = $this->chioschi())

    {{-- ═══ GLI ARMADI ═══ --}}
    <x-filament::section>
        <x-slot name="heading">Armadi ({{ $armadi->count() }})</x-slot>

        @forelse ($armadi as $armadio)
            @php($online = $armadio->isOnline())

            <a class="lk-riga" href="{{ $this->urlNodi($armadio) }}">
                <span @class(['lk-dot', 'lk-dot--on' => $online])></span>

                <span class="lk-riga__codice">{{ $armadio->code }}</span>
                <span class="lk-riga__nome">{{ $armadio->name }}</span>

                <span class="lk-riga__meta">
                    {{ $armadio->lockers_liberi_count }}/{{ $armadio->lockers_count }} liberi
                </span>

                @if ($armadio->lockers_rotti_count > 0)
                    <span class="lk-badge lk-badge--bad">{{ $armadio->lockers_rotti_count }} rotti</span>
                @endif

                {{-- ⚠️ Un armadio senza chiosco è lamiera: non apre niente. --}}
                <span class="lk-riga__meta lk-mono">
                    {{ $armadio->device?->serial ?? 'nessun chiosco' }}
                </span>
            </a>
        @empty
            <p class="lk-riga__nome">Questo locale non ha ancora armadi.</p>
        @endforelse
    </x-filament::section>

    {{-- ═══ I CHIOSCHI ═══ --}}
    <x-filament::section>
        <x-slot name="heading">Chioschi ({{ $chioschi->count() }})</x-slot>

        <x-slot name="description">
            ⚠️ Ci sono <strong>tutti</strong>, anche quelli registrati e non ancora legati a un armadio:
            è proprio quello che il tecnico ha appena montato e sta cercando.
        </x-slot>

        @forelse ($chioschi as $chiosco)
            <div class="lk-riga">
                <span class="lk-riga__codice lk-mono">{{ $chiosco->serial }}</span>

                <span @class([
                    'lk-badge',
                    'lk-badge--ok' => $chiosco->status === 'active',
                    'lk-badge--warn' => $chiosco->status === 'registered',
                    'lk-badge--bad' => $chiosco->status === 'revoked',
                ])>
                    {{ ['registered' => 'mai attivato', 'active' => 'attivo', 'revoked' => 'revocato'][$chiosco->status] ?? $chiosco->status }}
                </span>

                <span class="lk-riga__nome">
                    {{ $chiosco->cabinet?->code ?? '— non associato a nessun armadio —' }}
                </span>

                <span class="lk-riga__meta">{{ $chiosco->last_seen_at?->diffForHumans() ?? 'mai visto' }}</span>
            </div>
        @empty
            <p class="lk-riga__nome">Questo locale non ha ancora chioschi.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>

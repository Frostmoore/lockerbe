{{-- ⚠️ Polling: uno stato degli armadi fermo è uno stato sbagliato con sicurezza. --}}
<x-filament-panels::page wire:poll.10s>
    @php($locali = $this->locali())

    @forelse ($locali as $nomeLocale => $armadi)
        <section class="lk-locale">
            <h2 class="lk-locale__titolo">
                {{ $nomeLocale }}
                <span class="lk-locale__conta">{{ $armadi->count() }} {{ $armadi->count() === 1 ? 'armadio' : 'armadi' }}</span>
            </h2>

            <div class="lk-cards">
                @foreach ($armadi as $armadio)
                    @php($online = $armadio->isOnline())
                    @php($chiosco = $armadio->device)
                    @php($tot = max(1, $armadio->lockers_count))

                    {{-- ⚠️ La barra colorata a sinistra è la cosa che l'occhio trova per prima:
                         verde = quel guardaroba oggi apre, grigio = no, rosso = c'è del ferro
                         rotto. Tutto il resto è dettaglio. --}}
                    <a href="{{ $this->urlNodi($armadio) }}"
                       @class([
                           'lk-card',
                           'lk-card--online' => $online && $armadio->lockers_rotti_count === 0,
                           'lk-card--rotto' => $online && $armadio->lockers_rotti_count > 0,
                           'lk-card--offline' => ! $online,
                       ])>

                        <div class="lk-card__top">
                            <div>
                                <div class="lk-card__codice">
                                    <span @class(['lk-dot', 'lk-dot--on' => $online])></span>
                                    {{ $armadio->code }}
                                </div>
                                <p class="lk-card__nome">{{ $armadio->name }}</p>
                            </div>

                            <span @class(['lk-badge', 'lk-badge--ok' => $online, 'lk-badge--off' => ! $online])>
                                {{ $online ? 'online' : 'offline' }}
                            </span>
                        </div>

                        {{-- La barra dei vani: si legge senza contare. --}}
                        <div class="lk-barra">
                            <div class="lk-barra__pezzo--liberi" style="width: {{ 100 * $armadio->lockers_liberi_count / $tot }}%"></div>
                            <div class="lk-barra__pezzo--occupati" style="width: {{ 100 * $armadio->lockers_occupati_count / $tot }}%"></div>
                            <div class="lk-barra__pezzo--rotti" style="width: {{ 100 * $armadio->lockers_rotti_count / $tot }}%"></div>
                        </div>

                        <div class="lk-legenda">
                            <span class="lk-legenda__voce">
                                <span class="lk-legenda__quadro lk-barra__pezzo--liberi"></span>
                                <b>{{ $armadio->lockers_liberi_count }}</b> liberi
                            </span>
                            <span class="lk-legenda__voce">
                                <span class="lk-legenda__quadro lk-barra__pezzo--occupati"></span>
                                <b>{{ $armadio->lockers_occupati_count }}</b> occupati
                            </span>
                            @if ($armadio->lockers_rotti_count > 0)
                                <span class="lk-legenda__voce">
                                    <span class="lk-legenda__quadro lk-barra__pezzo--rotti"></span>
                                    <b>{{ $armadio->lockers_rotti_count }}</b> rotti
                                </span>
                            @endif
                        </div>

                        <div class="lk-card__piede">
                            @if ($chiosco)
                                <span class="lk-mono">{{ $chiosco->serial }}</span>
                                <span @class([
                                    'lk-badge',
                                    'lk-badge--ok' => $chiosco->status === 'active',
                                    'lk-badge--warn' => $chiosco->status === 'registered',
                                    'lk-badge--bad' => $chiosco->status === 'revoked',
                                ])>
                                    {{ ['registered' => 'mai attivato', 'active' => 'attivo', 'revoked' => 'revocato'][$chiosco->status] ?? $chiosco->status }}
                                </span>
                            @else
                                {{-- ⚠️ Un armadio senza chiosco è lamiera: non apre niente. --}}
                                <span class="lk-badge lk-badge--bad">nessun chiosco — non apre</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <div class="lk-vuoto">
            <strong>Nessun armadio.</strong><br>
            Registra un armadio, poi il chiosco che ci sta dentro, e premi <em>Attiva</em>.
        </div>
    @endforelse
</x-filament-panels::page>

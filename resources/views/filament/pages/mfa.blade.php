<x-filament-panels::page>
    @if ($attiva)
        <x-filament::section>
            <x-slot name="heading">Verifica in due passaggi attiva</x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Il tuo account chiede un codice dall'app di autenticazione a ogni accesso.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Collega l'app di autenticazione</x-slot>

            <x-slot name="description">
                @if ($this->obbligatoria())
                    La verifica in due passaggi è <strong>obbligatoria</strong> per il tuo ruolo:
                    finché non la configuri non puoi fare altro.
                @else
                    Non è obbligatoria per il tuo ruolo, ma è una buona idea.
                @endif
            </x-slot>

            <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                <img src="{{ $qr }}" alt="QR code" class="rounded-lg bg-white p-2 shadow" width="220" height="220">

                <div class="flex-1 space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Inquadra il QR con Google Authenticator (o simili). Se non puoi inquadrarlo,
                        inserisci a mano questo codice:
                    </p>

                    <code class="block rounded bg-gray-100 px-3 py-2 font-mono text-sm dark:bg-gray-800">{{ $secret }}</code>

                    <form wire:submit="conferma" class="space-y-3">
                        <div>
                            <label for="mfa-code" class="block text-sm font-medium">
                                Codice a 6 cifre
                            </label>

                            <input
                                id="mfa-code"
                                type="text"
                                inputmode="numeric"
                                maxlength="6"
                                autocomplete="one-time-code"
                                wire:model="code"
                                class="mt-1 block w-40 rounded-lg border-gray-300 font-mono tracking-widest shadow-sm dark:border-gray-600 dark:bg-gray-900"
                            >
                        </div>

                        {{-- Il segreto è salvato, ma la MFA si accende SOLO qui: prima bisogna
                             dimostrare di saper produrre un codice valido. --}}
                        <x-filament::button type="submit">Attiva</x-filament::button>
                    </form>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

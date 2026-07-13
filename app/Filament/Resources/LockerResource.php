<?php

namespace App\Filament\Resources;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Exceptions\DeviceOfflineException;
use App\Domain\Command\Services\CommandIssuer;
use App\Filament\Resources\LockerResource\Pages;
use App\Models\Locker;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * I VANI — la griglia che lo staff guarda tutto il giorno.
 *
 * ⚠️ Aggiornata a polling (10 secondi). Non è un vezzo: uno stato dei vani fermo è uno stato
 * **sbagliato con sicurezza**. Un operatore che vede "libero" un vano occupato tre secondi fa
 * ci mette dentro un secondo cappotto.
 *
 * ⚠️ I vani non si creano e non si cancellano da qui: nascono con l'armadio, insieme alla
 * loro mappa RS-485. Un vano in più nel database non è uno sportello in più nella lamiera.
 */
class LockerResource extends Resource
{
    protected static ?string $model = Locker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $modelLabel = 'vano';

    protected static ?string $pluralModelLabel = 'vani';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('number')
            ->columns([
                TextColumn::make('cabinet.code')->label('Armadio')->searchable()->sortable(),

                TextColumn::make('number')->label('Vano')->numeric()->sortable(),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'free' => 'success',
                        'reserved' => 'warning',
                        'occupied' => 'info',
                        'checkout' => 'warning',
                        'out_of_service' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'free' => 'Libero',
                        'reserved' => 'Prenotato',
                        'occupied' => 'Occupato',
                        'checkout' => 'In riconsegna',
                        'out_of_service' => 'Fuori servizio',
                        default => $state,
                    }),

                TextColumn::make('board_address')
                    ->label('Scheda / canale')
                    ->formatStateUsing(fn (Locker $record): string => "{$record->board_address} / {$record->channel}")
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_opened_at')->label('Ultima apertura')->since()->placeholder('mai'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'free' => 'Libero',
                    'reserved' => 'Prenotato',
                    'occupied' => 'Occupato',
                    'checkout' => 'In riconsegna',
                    'out_of_service' => 'Fuori servizio',
                ]),
                SelectFilter::make('cabinet')->label('Armadio')->relationship('cabinet', 'code'),
            ])
            ->recordActions([
                self::apriAction(),
                self::fuoriServizioAction(),
                self::rimettiInServizioAction(),
            ]);
    }

    /**
     * ⚠️ APRIRE UN VANO. È l'arma, e qui c'è solo il grilletto: le sicure stanno tutte in
     * `CommandIssuer`, che è l'unico posto che può creare un comando.
     *
     *  - armadio offline ⇒ **eccezione, e nessun comando creato**. Non si accoda una promessa
     *    di apertura: verrebbe consegnata ore dopo, aprendo un vano pieno di roba davanti a
     *    nessuno;
     *  - ogni comando **scade** (30 secondi);
     *  - `Idempotency-Key` — qui la genera il pannello, come farebbe un client: un doppio
     *    click non deve aprire due volte.
     */
    public static function apriAction(): Action
    {
        return Action::make('apri')
            ->label('Apri')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('warning')
            ->authorize(fn (Locker $record): bool => auth()->user()?->can('open', $record) ?? false)
            ->visible(fn (Locker $record): bool => ! $record->isOutOfService())
            ->requiresConfirmation()
            ->modalHeading('Aprire questo vano?')
            ->modalDescription('Lo sportello si apre fisicamente. Se dentro c\'è la roba di un cliente, resta accessibile.')
            ->action(function (Locker $record): void {
                try {
                    app(CommandIssuer::class)->issueOpen($record, 'admin', (string) Str::uuid7());
                } catch (DeviceOfflineException $e) {
                    // ⚠️ Non è un guasto del pannello: è la difesa più importante del sistema
                    // che fa il suo mestiere. L'operatore deve capirlo, non pensare a un bug.
                    Notification::make()
                        ->title('Armadio non raggiungibile.')
                        ->body('Nessun comando è stato creato: un\'apertura accodata verrebbe consegnata chissà quando.')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Apertura presa in carico.')
                    ->body('Il comando è partito e scade tra pochi secondi. L\'esito arriva con la risposta del chiosco.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Mettere un vano fuori servizio: la serratura è rotta, lo sportello non chiude.
     *
     * ⚠️ Un vano **occupato** non si mette fuori servizio: dentro c'è la roba di qualcuno, e
     * un vano fuori servizio non viene più assegnato né riaperto dal flusso normale — il
     * cliente tornerebbe e non riuscirebbe a riprendersi il cappotto.
     */
    public static function fuoriServizioAction(): Action
    {
        return Action::make('fuoriServizio')
            ->label('Fuori servizio')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('danger')
            ->authorize(fn (Locker $record): bool => auth()->user()?->can('service', $record) ?? false)
            ->visible(fn (Locker $record): bool => $record->status === 'free')
            ->schema([
                Textarea::make('reason')
                    ->label('Cosa non funziona?')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255),
            ])
            ->requiresConfirmation()
            ->action(function (Locker $record, array $data): void {
                /** @var User $attore */
                $attore = auth()->user();

                $record->forceFill(['status' => 'out_of_service'])->save();

                app(AuditLogger::class)->log('locker.out_of_service', [
                    'cabinet_id' => $record->cabinet_id,
                    'locker_id' => $record->id,
                    'actor' => $attore,
                    'context' => ['reason' => (string) $data['reason']],
                ]);

                Notification::make()->title('Vano fuori servizio.')->warning()->send();
            });
    }

    public static function rimettiInServizioAction(): Action
    {
        return Action::make('rimettiInServizio')
            ->label('Rimetti in servizio')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize(fn (Locker $record): bool => auth()->user()?->can('service', $record) ?? false)
            ->visible(fn (Locker $record): bool => $record->isOutOfService())
            ->requiresConfirmation()
            ->modalDescription('Assicurati che il vano sia vuoto e che lo sportello chiuda davvero.')
            ->action(function (Locker $record): void {
                /** @var User $attore */
                $attore = auth()->user();

                // ⚠️ Torna `free`, quindi **assegnabile**. Se dentro c'è ancora qualcosa, il
                // prossimo cliente ci troverà la roba di un altro. Per questo la conferma
                // dice di controllare: il sistema non può saperlo, il mondo fisico sì.
                $record->forceFill(['status' => 'free', 'current_session_id' => null])->save();

                app(AuditLogger::class)->log('locker.in_service', [
                    'cabinet_id' => $record->cabinet_id,
                    'locker_id' => $record->id,
                    'actor' => $attore,
                ]);

                Notification::make()->title('Vano di nuovo in servizio.')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLockers::route('/')];
    }
}

<?php

namespace App\Filament\Resources;

use App\Domain\Session\Services\SessionManager;
use App\Filament\Resources\SessionResource\Pages;
use App\Models\Session;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * LE SESSIONI — un cappotto dentro un vano, dal pagamento alla riconsegna.
 *
 * ⚠️ Sola lettura, salvo le due azioni qui sotto. Una sessione non si modifica a mano:
 * cambia stato solo passando da `SessionManager`, dove la macchina a stati è scritta una
 * volta sola. Un campo `status` editabile dal pannello sarebbe il modo più veloce di
 * ottenere un vano "libero" con dentro la roba di qualcuno.
 */
class SessionResource extends Resource
{
    protected static ?string $model = Session::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $modelLabel = 'sessione';

    protected static ?string $pluralModelLabel = 'sessioni';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('cabinet.code')->label('Armadio')->sortable(),

                TextColumn::make('locker.number')->label('Vano')->numeric()->sortable(),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending_payment' => 'warning',
                        'checkout' => 'info',
                        'closed' => 'gray',
                        'cancelled', 'expired' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_payment' => 'In attesa di pagamento',
                        'active' => 'Attiva',
                        'checkout' => 'In riconsegna',
                        'closed' => 'Chiusa',
                        'cancelled' => 'Annullata',
                        'expired' => 'Scaduta',
                        default => $state,
                    }),

                TextColumn::make('amount_cents')
                    ->label('Importo')
                    ->money('EUR', divideBy: 100),

                TextColumn::make('reopen_count')->label('Riaperture')->numeric(),

                TextColumn::make('closed_by')
                    ->label('Chiusa da')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'device' => 'sportello richiuso',
                        'timeout' => 'scaduto il tempo',
                        'staff' => 'staff',
                        default => $state,
                    }),

                TextColumn::make('created_at')->label('Aperta')->dateTime('d/m H:i')->sortable(),
                TextColumn::make('closed_at')->label('Chiusa')->dateTime('d/m H:i')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'pending_payment' => 'In attesa di pagamento',
                    'active' => 'Attiva',
                    'checkout' => 'In riconsegna',
                    'closed' => 'Chiusa',
                    'cancelled' => 'Annullata',
                    'expired' => 'Scaduta',
                ]),
            ])
            ->recordActions([
                self::checkoutAction(),
                self::confermaCheckoutAction(),
            ]);
    }

    /**
     * Avviare la riconsegna al posto del cliente: apre il vano e mette la sessione in
     * `checkout`.
     *
     * Serve quando il cliente non ce la fa da solo — la carta non legge, il chiosco è
     * spento, il cliente ha perso il QR. È il motivo per cui lo staff esiste.
     */
    public static function checkoutAction(): Action
    {
        return Action::make('checkout')
            ->label('Riconsegna')
            ->icon(Heroicon::OutlinedArrowUpOnSquare)
            ->color('warning')
            ->authorize(fn (Session $record): bool => auth()->user()?->can('checkout', $record) ?? false)
            ->visible(fn (Session $record): bool => $record->isActive())
            ->requiresConfirmation()
            ->modalHeading('Avviare la riconsegna?')
            ->modalDescription('Il vano si apre. Resta assegnato al cliente finché non è confermato vuoto.')
            ->action(function (Session $record): void {
                try {
                    app(SessionManager::class)->checkout($record);
                } catch (Throwable $e) {
                    Notification::make()->title('Non si può.')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Vano aperto.')
                    ->body('Il vano si libera quando lo sportello viene richiuso, o allo scadere della finestra.')
                    ->success()
                    ->send();
            });
    }

    /**
     * ⚠️ LA CONFERMA CHE IL VANO È VUOTO. Il gesto più delicato del pannello.
     *
     * Il sistema **non può sapere** se dentro c'è ancora qualcosa: non ha occhi. Per questo
     * il principio è che *un'azione ambigua non deve mai liberare un vano* (§7.0) — e questo
     * bottone è l'eccezione deliberata: un essere umano ha guardato dentro e dice che è vuoto.
     *
     * `closed_by = 'staff'` proprio per questo: nel registro deve restare che a liberarlo non
     * è stato uno sportello richiuso né un timer, ma **una persona che si è presa la
     * responsabilità di guardare**.
     */
    public static function confermaCheckoutAction(): Action
    {
        return Action::make('confermaCheckout')
            ->label('Confermo: è vuoto')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->authorize(fn (Session $record): bool => auth()->user()?->can('checkout', $record) ?? false)
            ->visible(fn (Session $record): bool => $record->isCheckoutPending())
            ->requiresConfirmation()
            ->modalHeading('Il vano è davvero vuoto?')
            ->modalDescription('Da questo momento torna assegnabile: il prossimo cliente ci troverà quello che c\'è rimasto.')
            ->action(function (Session $record): void {
                app(SessionManager::class)->confirmCheckout($record, 'staff');

                Notification::make()->title('Vano liberato.')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSessions::route('/')];
    }
}

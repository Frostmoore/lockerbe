<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommandResource\Pages;
use App\Models\Command;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * I COMANDI mandati agli armadi. **Sola lettura**, e nemmeno un bottone per crearne uno.
 *
 * ⚠️ Un comando nasce solo da `CommandIssuer`, perché è lì che vivono le tre difese (armadio
 * offline ⇒ niente comando · scadenza · idempotenza) e la firma. Un comando creato a mano
 * dal pannello sarebbe un comando senza scadenza e senza firma — cioè esattamente
 * l'apertura che tutto il resto del sistema esiste per impedire.
 *
 * Questa pagina serve a rispondere a una domanda sola, ma è la domanda che conta:
 * **quel vano si è aperto, sì o no?**
 */
class CommandResource extends Resource
{
    protected static ?string $model = Command::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $modelLabel = 'comando';

    protected static ?string $pluralModelLabel = 'comandi';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            // Un comando vive 30 secondi: guardarlo a schermo fermo significa non vederlo mai.
            ->poll('5s')
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('issued_at')->label('Emesso')->dateTime('d/m H:i:s')->sortable(),

                TextColumn::make('cabinet.code')->label('Armadio')->sortable(),
                TextColumn::make('locker.number')->label('Vano')->numeric()->placeholder('—'),

                TextColumn::make('type')->label('Tipo')->badge(),

                TextColumn::make('reason')
                    ->label('Perché')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'store' => 'deposito',
                        'reopen' => 'riapertura',
                        'checkout' => 'riconsegna',
                        'admin' => 'staff',
                        'maintenance' => 'manutenzione',
                        default => $state,
                    }),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'acked' => 'success',
                        'sent' => 'info',
                        'pending' => 'warning',
                        'expired' => 'danger',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'in coda',
                        'sent' => 'consegnato',
                        'acked' => 'eseguito',
                        'expired' => 'scaduto',
                        'failed' => 'fallito',
                        default => $state,
                    })
                    // ⚠️ `pending` a lungo non è "sta arrivando": è il segno che **nessuno sta
                    // pubblicando**. Quasi sempre significa che `queue:work` non gira.
                    ->description(fn (Command $record): ?string => $record->status === 'pending' && $record->isExpired()
                        ? 'scaduto in coda: nessuno lo ha pubblicato'
                        : null),

                TextColumn::make('expires_at')->label('Scade')->dateTime('H:i:s'),
                TextColumn::make('acked_at')->label('Risposta')->dateTime('H:i:s')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'pending' => 'In coda',
                    'sent' => 'Consegnato',
                    'acked' => 'Eseguito',
                    'expired' => 'Scaduto',
                    'failed' => 'Fallito',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCommands::route('/')];
    }
}

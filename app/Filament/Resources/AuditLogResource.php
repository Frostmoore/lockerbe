<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * IL REGISTRO. Si legge, e basta.
 *
 * ⚠️ Non c'è modo di scrivere qui dentro dal pannello, e non è una scelta di prodotto: è
 * **imposta dal database**. A `locker_app` — il ruolo con cui gira l'applicazione — sono
 * stati revocati UPDATE e DELETE su questa tabella, e ogni riga è concatenata alla
 * precedente con un hash. Riscrivere la storia richiederebbe un altro ruolo e romperebbe la
 * catena, che `audit:verify` controlla.
 *
 * Il pannello non offre nemmeno il bottone. Un "elimina" che poi esplode con un errore di
 * Postgres sarebbe peggio di nessun bottone: insegnerebbe che il sistema è rotto, invece
 * che rigoroso.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'voce di registro';

    protected static ?string $pluralModelLabel = 'registro';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s')->sortable(),

                TextColumn::make('action')->label('Azione')->badge()->searchable()->sortable(),

                TextColumn::make('actor_type')
                    ->label('Chi')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'user' => 'persona',
                        'device' => 'chiosco',
                        'system' => 'sistema',
                        'webhook' => 'webhook',
                        default => $state,
                    }),

                TextColumn::make('actor_role')->label('Ruolo')->placeholder('—'),

                TextColumn::make('result')
                    ->label('Esito')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ok' ? 'success' : 'danger'),

                TextColumn::make('error_code')->label('Errore')->placeholder('—'),

                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),

                // ⚠️ La catena è la ragione per cui questo registro vale qualcosa: ogni riga
                // porta l'hash della precedente, quindi cancellarne una in mezzo si vede.
                TextColumn::make('hash')
                    ->label('Hash')
                    ->limit(12)
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('result')->label('Esito')->options(['ok' => 'OK', 'fail' => 'Fallito']),
                SelectFilter::make('actor_type')->label('Chi')->options([
                    'user' => 'Persona',
                    'device' => 'Chiosco',
                    'system' => 'Sistema',
                    'webhook' => 'Webhook',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}

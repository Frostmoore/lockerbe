<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * I LOCALI. Solo noi (`tenant.manage` è un permesso di `platform_admin` e basta).
 *
 * ⚠️ Un `tenant_admin` che potesse creare tenant potrebbe creare **il contenitore in cui
 * mettere qualcosa che non è suo**: l'intera separazione tra clienti si regge sul fatto che
 * quel contenitore lo assegniamo noi. Per questo la risorsa è registrata solo nel pannello
 * di piattaforma **e** la policy lo ripete — registrarla in un pannello solo è
 * organizzazione, non sicurezza.
 *
 * ⚠️ Un locale non si cancella: si **sospende**. Cancellarlo si porterebbe dietro armadi,
 * sessioni e soprattutto l'audit — che è append-only proprio perché nessuno possa far
 * sparire quello che è successo.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'locale';

    protected static ?string $pluralModelLabel = 'locali';

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(120),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true),

            Select::make('status')
                ->label('Stato')
                ->options(['active' => 'Attivo', 'suspended' => 'Sospeso'])
                ->default('active')
                ->required(),

            TextInput::make('timezone')->label('Fuso orario')->default('Europe/Rome')->required(),

            /*
             * ⚠️ La durata predefinita della prenotazione: la ereditano tutti gli armadi che
             * non ne hanno una propria. È una decisione commerciale — un locale di passaggio la
             * vuole corta, un teatro lunga — e per questo sta qui e non in un file di config.
             */
            TextInput::make('settings.reservation_ttl')
                ->label('Durata predefinita della prenotazione')
                ->suffix('minuti')
                ->numeric()
                ->minValue(1)
                ->maxValue(120)
                ->helperText('Quanto tempo ha il cliente per pagare, prima che il vano torni libero.')
                ->formatStateUsing(fn (mixed $state): int => (int) round(((int) ($state ?? config('locker.reservation.ttl'))) / 60))
                ->dehydrateStateUsing(fn (?string $state): int => filled($state) ? (int) $state * 60 : (int) config('locker.reservation.ttl')),

            // ⚠️ Un locale può RENDERE OBBLIGATORIA la MFA, non può renderla facoltativa se la
            // piattaforma la impone: un'impostazione di tenant non deve poter essere più
            // permissiva di quella di piattaforma, altrimenti l'obbligo sarebbe una cortesia.
            Toggle::make('settings.require_mfa')
                ->label('Verifica in due passaggi obbligatoria')
                ->helperText('Se la piattaforma la impone già, questa non può disattivarla.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable()->fontFamily('mono'),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),

                TextColumn::make('cabinets_count')->label('Armadi')->counts('cabinets'),
                TextColumn::make('users_count')->label('Utenti')->counts('users'),

                IconColumn::make('mfa')
                    ->label('MFA obbligatoria')
                    ->boolean()
                    ->getStateUsing(fn (Tenant $record): bool => $record->requiresMfa()),
            ])
            ->recordUrl(fn (Tenant $record): string => self::getUrl('dettaglio', ['record' => $record]))
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),

            // ⚠️ La domanda del supporto al telefono è *"cos'ha, questo cliente?"* — e la
            // risposta non è un nome e uno slug: sono i suoi armadi e i suoi chioschi.
            'dettaglio' => Pages\DettaglioTenant::route('/{record}'),

            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}

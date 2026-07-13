<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/**
 * GLI ACCOUNT — le persone che *gestiscono* il sistema.
 *
 * ⚠️ Il cliente che deposita il cappotto **non è qui dentro e non deve esserci**: non ha un
 * account (piano §4). Riapre il proprio vano con la carta o col token che gli abbiamo dato
 * al pagamento. Se un giorno comparisse un ruolo `end_user`, vorrebbe dire che abbiamo
 * sbagliato qualcosa di grosso.
 *
 * ⚠️ I ruoli che si possono assegnare dipendono da chi guarda: un `tenant_admin` può creare
 * staff e altri admin **del proprio locale**, ma non può creare un `platform_admin` — cioè
 * non può fabbricarsi un account che vede tutti gli altri clienti.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'utente';

    protected static ?string $pluralModelLabel = 'utenti';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(120),

            TextInput::make('username')
                ->label('Username')
                ->required()
                ->maxLength(60)
                ->unique(ignoreRecord: true),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true),

            // ⚠️ Vuota in modifica = "non cambiarla". Se salvassimo la stringa vuota,
            // modificare il nome di un utente gli azzererebbe la password.
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                ->helperText('In modifica: lasciala vuota per non cambiarla.'),

            Select::make('roles')
                ->label('Ruolo')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->required()
                // ⚠️ `platform_admin` non è nella lista di chi non è platform_admin. Un
                // tenant_admin che potesse assegnarlo si promuoverebbe a vedere tutti i
                // clienti — e sarebbe l'unica riga di codice necessaria per farlo.
                ->options(fn (): array => auth()->user()?->isPlatformAdmin()
                    ? ['platform_admin' => 'Platform admin', 'tenant_admin' => 'Gestore del locale', 'tenant_staff' => 'Staff']
                    : ['tenant_admin' => 'Gestore del locale', 'tenant_staff' => 'Staff']),

            Select::make('status')
                ->label('Stato')
                ->options(['active' => 'Attivo', 'disabled' => 'Disabilitato'])
                ->default('active')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('username')->label('Username')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),

                TextColumn::make('tenant.name')
                    ->label('Locale')
                    ->placeholder('— piattaforma —')
                    ->visible(fn (): bool => filament()->getCurrentPanel()?->getId() === 'admin'),

                TextColumn::make('roles.name')->label('Ruoli')->badge(),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),

                IconColumn::make('two_factor_confirmed_at')
                    ->label('MFA')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->hasMfaEnabled()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}

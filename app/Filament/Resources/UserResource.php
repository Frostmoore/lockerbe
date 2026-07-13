<?php

namespace App\Filament\Resources;

use App\Domain\Audit\AuditLogger;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

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
                self::resetPasswordAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * ⚠️ MANDA IL LINK DI RESET PASSWORD.
     *
     * Non mostra la nuova password a schermo, e non la sceglie l'admin: gli manda un **link a
     * tempo**, e la password se la sceglie lui. Un admin che digita la password di un altro è
     * un admin che la conosce — e da quel momento nessuna riga dell'audit può più dire con
     * certezza *chi* ha fatto una cosa con quell'account.
     *
     * ⚠️ **Oggi la mail finisce nel log** (`MAIL_MAILER=log`): non c'è ancora un provider di
     * posta. Il link è vero e funziona; sta in `storage/logs/laravel.log`. Quando ci sarà un
     * provider, **non cambia una riga di questo codice**: cambia una variabile d'ambiente.
     *
     * ⚠️ Usiamo la notifica di **Filament**, non quella di Laravel: quella di Laravel costruisce
     * l'URL con `route('password.reset')`, che nei pannelli **non esiste**. Manderemmo un link
     * rotto — cioè, dal punto di vista di chi lo riceve, nessun link.
     */
    public static function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Manda reset password')
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->authorize(fn (User $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Mandare il link di reset?')
            ->modalDescription('Riceverà un link a tempo per scegliersi una nuova password. La password attuale resta valida finché non la cambia.')
            ->action(function (User $record): void {
                /** @var User $attore */
                $attore = auth()->user();

                Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                    ['email' => $record->email],
                    function (User $utente, string $token): void {
                        $notifica = app(ResetPassword::class, ['token' => $token]);
                        $notifica->url = Filament::getResetPasswordUrl($token, $utente);

                        $utente->notify($notifica);
                    },
                );

                app(AuditLogger::class)->log('user.password_reset_sent', [
                    'actor' => $attore,
                    'context' => ['target' => $record->username ?? $record->email],
                ]);

                Notification::make()
                    ->title('Link di reset mandato.')
                    ->body('⚠️ Non c\'è ancora un provider di posta: la mail è finita in storage/logs/laravel.log.')
                    ->success()
                    ->send();
            });
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

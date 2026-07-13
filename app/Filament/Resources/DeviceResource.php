<?php

namespace App\Filament\Resources;

use App\Domain\Device\Services\DeviceProvisioningService;
use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use App\Models\User;
use App\Support\MockPanel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * I CHIOSCHI (FCV5003).
 *
 * ⚠️ Il chiosco e l'armadio sono **un unico oggetto fisico**: un cubo di lamiera con degli
 * sportelli e uno schermo avvitato in mezzo. Sono due entità nel software solo perché il
 * chiosco si può rompere e sostituire senza cambiare la lamiera.
 *
 * Il flusso del tecnico, ed è tutto qui:
 *   1. registra il chiosco col **serial letto dall'etichetta**;
 *   2. lo lega all'armadio;
 *   3. preme **Attiva**.
 *
 * ⚠️ **Attiva è anche il bottone della ri-abilitazione.** Stesso click, nuovo segreto, stesso
 * armadio. Un solo gesto da imparare — perché un gesto che si impara è un gesto che si fa,
 * mentre una procedura complicata è una procedura che il tecnico, alle sette di sera, salta.
 */
class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $modelLabel = 'chiosco';

    protected static ?string $pluralModelLabel = 'chioschi';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Come per gli armadi: il tecnico lavora in bypass, non "appartiene" a un locale,
            // quindi il locale deve dirlo lui. Nel pannello del locale il campo non esiste.
            Select::make('tenant_id')
                ->label('Locale')
                ->relationship('tenant', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->visible(fn (): bool => filament()->getCurrentPanel()?->getId() === 'admin')
                ->visibleOn('create')
                ->dehydrated(false),

            TextInput::make('serial')
                ->label('Serial')
                ->helperText('Quello stampato sull\'etichetta del dispositivo. Es. FCV5003-0001.')
                ->required()
                ->maxLength(64)
                ->disabledOn('edit'),

            TextInput::make('model')
                ->label('Modello')
                ->default('FCV5003')
                ->maxLength(64),

            Select::make('cabinet_id')
                ->label('Armadio')
                ->relationship('cabinet', 'code')
                ->searchable()
                ->preload()
                ->helperText('L\'armadio in cui è avvitato. Si può legare anche dopo.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('15s')
            ->columns([
                TextColumn::make('serial')->label('Serial')->searchable()->sortable()->fontFamily('mono'),

                TextColumn::make('cabinet.code')->label('Armadio')->placeholder('non associato')->sortable(),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'registered' => 'warning',
                        'revoked' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'registered' => 'registrato, mai attivato',
                        'active' => 'attivo',
                        'revoked' => 'revocato',
                        default => $state,
                    }),

                TextColumn::make('last_seen_at')->label('Ultimo heartbeat')->since()->placeholder('mai'),

                // ⚠️ La finestra è il cuore della sicurezza dell'arruolamento: solo dentro
                // questi 15 minuti il chiosco può ritirare le proprie credenziali. Fuori,
                // chiunque bussi con quel serial non ottiene niente.
                TextColumn::make('activation_expires_at')
                    ->label('Finestra di attivazione')
                    ->since()
                    ->placeholder('chiusa')
                    ->color(fn (Device $record): string => $record->hasOpenActivationWindow() ? 'warning' : 'gray'),
            ])
            ->recordActions([
                self::attivaAction(),
                self::emulatoreAction(),
                self::revocaAction(),
            ]);
    }

    /**
     * ⚠️ ATTIVA — e ri-attiva.
     *
     * Apre una finestra a tempo (15 minuti) dentro la quale il chiosco può venire a
     * ritirarsi le credenziali MQTT. Fuori dalla finestra, nessuno le ottiene.
     *
     * Il modello di minaccia è quello vero, non quello di scuola: **se qualcuno ha in mano il
     * dispositivo, la catena di sicurezza è già rotta** — è un armadio di lamiera con uno
     * schermo avvitato dentro, e chi lo ha smontato ha già il problema più grande. Quindi le
     * credenziali sul device non si difendono con l'ossessione: si difende il *momento* in
     * cui vengono consegnate, e si rende **banale revocarle e rifarle**.
     */
    public static function attivaAction(): Action
    {
        return Action::make('attiva')
            ->label(fn (Device $record): string => $record->status === 'active' ? 'Ri-attiva' : 'Attiva')
            ->icon(Heroicon::OutlinedBoltSlash)
            ->color('warning')
            ->authorize(fn (Device $record): bool => auth()->user()?->can('activate', $record) ?? false)
            ->visible(fn (Device $record): bool => $record->cabinet_id !== null)
            ->requiresConfirmation()
            ->modalHeading('Aprire la finestra di attivazione?')
            ->modalDescription('Per 15 minuti il chiosco potrà ritirare le sue credenziali. Le vecchie smettono di valere subito.')
            ->action(function (Device $record): void {
                /** @var User $attore */
                $attore = auth()->user();

                app(DeviceProvisioningService::class)->activate($record, $attore);

                Notification::make()
                    ->title('Finestra aperta: 15 minuti.')
                    ->body('Accendi il chiosco adesso. Ritirerà le credenziali da solo.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Il collegamento all'emulatore (F5) — ⚠️ doppio cancello: non esiste in produzione.
     *
     * Sta qui perché è qui che serve: il tecnico ha appena premuto Attiva e vuole vedere se
     * il chiosco si connette. Senza questo link dovrebbe andare a pescare un uuid.
     */
    public static function emulatoreAction(): Action
    {
        return Action::make('emulatore')
            ->label('Apri emulatore')
            ->icon(Heroicon::OutlinedComputerDesktop)
            ->color('gray')
            ->visible(fn (Device $record): bool => MockPanel::enabled() && $record->cabinet_id !== null)
            ->url(fn (Device $record): string => url('/emulator/'.$record->cabinet_id))
            ->openUrlInNewTab();
    }

    /**
     * ⚠️ REVOCA — il chiosco è stato rubato, o sostituito.
     *
     * L'effetto è **immediato**, e questo è tutto il senso dell'architettura MQTT che abbiamo
     * scelto: il broker non tiene un elenco di utenti, chiede a noi a ogni connessione. Con
     * dei file `passwd` statici, un chiosco revocato resterebbe dentro fino a un
     * rigenera-e-ricarica — cioè "finché qualcuno se ne ricorda".
     */
    public static function revocaAction(): Action
    {
        return Action::make('revoca')
            ->label('Revoca')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize(fn (Device $record): bool => auth()->user()?->can('revoke', $record) ?? false)
            ->visible(fn (Device $record): bool => ! $record->isRevoked())
            ->schema([
                Textarea::make('reason')
                    ->label('Perché lo stai revocando?')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255),
            ])
            ->requiresConfirmation()
            ->modalDescription('Da subito il broker rifiuta le sue credenziali. L\'armadio smette di aprirsi.')
            ->action(function (Device $record, array $data): void {
                /** @var User $attore */
                $attore = auth()->user();

                app(DeviceProvisioningService::class)->revoke($record, $attore, (string) $data['reason']);

                Notification::make()
                    ->title('Chiosco revocato.')
                    ->body('Effetto immediato: il broker lo rifiuta già adesso.')
                    ->warning()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}

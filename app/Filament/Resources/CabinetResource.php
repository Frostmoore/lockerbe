<?php

namespace App\Filament\Resources;

use App\Domain\Audit\AuditLogger;
use App\Domain\Command\Services\CommandIssuer;
use App\Filament\Resources\CabinetResource\Pages;
use App\Models\Cabinet;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * GLI ARMADI.
 *
 * ⚠️ La stessa classe gira in **entrambi** i pannelli: in `/app` mostra solo gli armadi del
 * proprio locale, in `/admin` quelli di tutti. Non c'e' un `if` da nessuna parte, e non deve
 * esserci: il filtro sta nel **database** (RLS + global scope), non nella risorsa. Una
 * risorsa che filtrasse da sola sarebbe una risorsa che, il giorno che qualcuno dimentica
 * il filtro su una query, perde silenziosamente il confine tra clienti.
 */
class CabinetResource extends Resource
{
    protected static ?string $model = Cabinet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'armadio';

    protected static ?string $pluralModelLabel = 'armadi';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // ⚠️ Solo nel pannello di piattaforma, e solo alla creazione. Il tecnico che
            // registra un armadio lavora in bypass — non "appartiene" a nessun locale — e
            // quindi il locale deve dirlo lui, esplicitamente. Nel pannello del locale il
            // campo non esiste: il tenant lo mette il contesto, e non c'è niente da scegliere.
            Select::make('tenant_id')
                ->label('Locale')
                ->relationship('tenant', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->visible(fn (): bool => filament()->getCurrentPanel()?->getId() === 'admin')
                ->visibleOn('create')
                ->dehydrated(false),

            TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(120),

            TextInput::make('code')
                ->label('Codice')
                ->helperText('Identificativo breve, unico nel locale. Es. GUARDAROBA-1.')
                ->required()
                ->maxLength(40),

            // ⚠️ Solo alla creazione: il numero di vani descrive un fatto FISICO (quante
            // porte ha quella lamiera). Cambiarlo dopo non aggiunge sportelli — creerebbe
            // solo vani che il mondo reale non ha, e prima o poi qualcuno ci proverebbe a
            // depositare un cappotto.
            TextInput::make('locker_count')
                ->label('Numero di vani')
                ->helperText('Quanti sportelli ha fisicamente questo armadio. Non è più modificabile.')
                ->numeric()
                ->minValue(1)
                ->maxValue(200)
                ->required()
                ->visibleOn('create')
                ->dehydrated(false),

            Select::make('status')
                ->label('Stato')
                ->options([
                    'offline' => 'Offline',
                    'online' => 'Online',
                    'maintenance' => 'Manutenzione',
                ])
                ->disabled()
                ->helperText('Non si imposta a mano: lo dice il chiosco, con il suo heartbeat.')
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // ⚠️ Il pannello dice se un armadio è raggiungibile: un'informazione che invecchia
            // in 90 secondi. Senza polling mostrerebbe con sicurezza uno stato falso.
            ->poll('15s')
            ->columns([
                TextColumn::make('code')->label('Codice')->searchable()->sortable(),
                TextColumn::make('name')->label('Nome')->searchable(),

                TextColumn::make('tenant.name')
                    ->label('Locale')
                    // Nel pannello del locale è rumore: c'è un locale solo, il proprio.
                    ->visible(fn (): bool => filament()->getCurrentPanel()?->getId() === 'admin')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'maintenance' => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('lockers_count')
                    ->label('Vani')
                    ->counts('lockers'),

                TextColumn::make('last_seen_at')
                    ->label('Visto l\'ultima volta')
                    ->since()
                    ->placeholder('mai'),

                TextColumn::make('firmware_version')->label('Firmware')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options([
                    'online' => 'Online',
                    'offline' => 'Offline',
                    'maintenance' => 'Manutenzione',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                self::apriTuttiAction(),
            ]);
    }

    /**
     * ⚠️⚠️ APRI TUTTI I VANI. L'azione più pericolosa del sistema: svuota il guardaroba.
     *
     * Tre freni, e nessuno dei tre è decorativo:
     *
     *  1. **Il permesso.** `locker.open_all` non è di `tenant_staff` — resta al gestore, che
     *     ne risponde. ⚠️ Filament nasconde il bottone a chi non ce l'ha, ma **nascondere un
     *     bottone non è sicurezza**: la policy lo blocca comunque, e l'endpoint API pure.
     *     C'è un test che verifica proprio questo.
     *  2. **La motivazione, obbligatoria.** Non serve a noi: serve a chi la scrive. Dover
     *     digitare *perché* si sta per svuotare un guardaroba è l'ultimo momento utile per
     *     accorgersi di non volerlo fare davvero.
     *  3. **La spunta di conferma.** Un secondo gesto, deliberato, su un'azione che non si
     *     può annullare: i cappotti, una volta che gli sportelli sono aperti, sono aperti.
     *
     * Finisce nell'audit **prima** di partire, con la motivazione: se qualcosa va storto, la
     * traccia di *chi* e *perché* esiste già.
     */
    public static function apriTuttiAction(): Action
    {
        return Action::make('apriTutti')
            ->label('Apri tutti')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('danger')
            ->authorize(fn (Cabinet $record): bool => auth()->user()?->can('openAll', $record) ?? false)
            ->schema([
                Textarea::make('reason')
                    ->label('Perché stai aprendo tutti i vani?')
                    ->helperText('Finisce nel registro, insieme al tuo nome. Minimo 5 caratteri.')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255),

                Toggle::make('confirm')
                    ->label('Ho capito: sto per aprire ogni sportello di questo armadio.')
                    ->accepted()
                    ->required(),
            ])
            ->modalHeading('Aprire TUTTI i vani?')
            ->modalDescription('Ogni sportello non fuori servizio verrà aperto. Non si torna indietro.')
            ->modalSubmitActionLabel('Apri tutti i vani')
            ->requiresConfirmation()
            ->action(function (Cabinet $record, array $data): void {
                /** @var User $attore */
                $attore = auth()->user();

                $vani = $record->lockers()
                    ->whereNot('status', 'out_of_service')
                    ->orderBy('number')
                    ->get();

                // ⚠️ Prima l'audit, poi i comandi: se il registro non riesce a scrivere, non
                // si apre niente. Il contrario — aprire e poi provare a registrare — è come
                // apriamo un guardaroba senza sapere chi è stato.
                app(AuditLogger::class)->log('cabinet.open_all', [
                    'cabinet_id' => $record->id,
                    'actor' => $attore,
                    'context' => ['reason' => (string) $data['reason'], 'vani' => $vani->count()],
                ]);

                $issuer = app(CommandIssuer::class);

                foreach ($vani as $vano) {
                    // ⚠️ Un comando per vano, ciascuno con la SUA scadenza e la SUA firma. Un
                    // unico "comando gigante" sarebbe impossibile da far scadere per parti: o
                    // arriva tutto, o niente — e "tutto, in ritardo" è il caso peggiore.
                    $issuer->issueOpen($vano, 'admin', (string) Str::uuid7());
                }

                Notification::make()
                    ->title("Apertura presa in carico: {$vani->count()} vani.")
                    ->body('Ogni vano ha il suo comando, con la sua scadenza. Guarda i Comandi per l\'esito.')
                    ->warning()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCabinets::route('/'),
            'create' => Pages\CreateCabinet::route('/create'),
            'edit' => Pages\EditCabinet::route('/{record}/edit'),
        ];
    }
}

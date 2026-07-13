<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Cabinet;
use App\Models\Locker;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * IL REGISTRO — come un file di log, non come una tabella.
 *
 * ⚠️ Una tabella con dieci colonne di uuid non si legge: si *consulta*, e solo se sai già
 * cosa cerchi. Ma questa pagina serve a rispondere a domande che uno si fa **dopo**, quando
 * qualcosa è andato storto — *"chi ha aperto quel vano?"*, *"perché ha svuotato l'armadio?"* —
 * e allora servono frasi, non celle.
 *
 * Ogni riga è una frase in italiano, con il pezzo che conta evidenziato. Gli uuid diventano
 * cose: `019f5c…` diventa *"vano 3 dell'armadio GUARDAROBA-1"*.
 *
 * ⚠️ **Sola lettura, e non per scelta nostra**: il database ha revocato UPDATE e DELETE al
 * ruolo con cui gira l'applicazione, e ogni riga porta l'hash della precedente. Cancellarne
 * una in mezzo si vede.
 *
 * ⚠️ **Non filtra il tenant**: ci pensano RLS e global scope. In `/admin` (bypass) si vede
 * tutto — comprese le righe di sistema, che non hanno tenant; in `/app` solo il proprio locale.
 */
class Registro extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Registro';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'registro';

    protected string $view = 'filament.pages.registro';

    /**
     * ⚠️ Le azioni che, da sole, possono svuotare un guardaroba o togliere di mezzo un chiosco.
     * Nel log non devono somigliare alle altre: sono in rosso, in grassetto.
     *
     * @var list<string>
     */
    private const GRAVI = [
        'cabinet.open_all',
        'device.revoked',
        'locker.error',
        'auth.mfa.disabled',
    ];

    /** Il testo cercato: azione, username, codice armadio, IP… */
    public string $cerca = '';

    /** `''` = tutto · `fail` = solo ciò che è andato storto. */
    public string $soloErrori = '';

    public int $quante = 100;

    public function getTitle(): string|Htmlable
    {
        return 'Registro';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', AuditLog::class) ?? false;
    }

    public function diPiu(): void
    {
        $this->quante += 100;
    }

    /**
     * Le righe, già tradotte in italiano.
     *
     * @return Collection<int, array{quando: Carbon|null, ok: bool, azione: string, frase: string, grave: bool, chi: string, dove: string|null, errore: string|null, ip: string|null, contesto: array<string, string>, hash: string}>
     */
    public function righe(): Collection
    {
        $query = AuditLog::query()->orderByDesc('id');

        if ($this->soloErrori === 'fail') {
            $query->where('result', 'fail');
        }

        if ($this->cerca !== '') {
            $ago = '%'.$this->cerca.'%';

            $query->where(fn ($q) => $q
                ->where('action', 'ilike', $ago)
                ->orWhere('actor_role', 'ilike', $ago)
                ->orWhere('error_code', 'ilike', $ago)
                ->orWhere('ip', 'ilike', $ago)
                ->orWhere('context', 'ilike', $ago)
            );
        }

        $voci = $query->limit($this->quante)->get();

        // ⚠️ Gli uuid si risolvono in blocco, non uno per riga: un registro che apre 300 query
        // per mostrare 100 righe è un registro che nessuno guarderà mai due volte.
        $attori = User::query()->withoutGlobalScopes()
            ->whereIn('id', $voci->pluck('actor_id')->filter()->unique())
            ->get()->keyBy('id');

        $armadi = Cabinet::query()->withoutGlobalScopes()
            ->whereIn('id', $voci->pluck('cabinet_id')->filter()->unique())
            ->get()->keyBy('id');

        $vani = Locker::query()->withoutGlobalScopes()
            ->whereIn('id', $voci->pluck('locker_id')->filter()->unique())
            ->get()->keyBy('id');

        return $voci->map(fn (AuditLog $v): array => [
            'quando' => $v->created_at,
            'ok' => $v->result === 'ok',
            'azione' => $v->action,
            'frase' => self::frase($v->action),

            // ⚠️ Le azioni che possono svuotare un guardaroba non devono somigliare alle altre.
            'grave' => in_array($v->action, self::GRAVI, true),

            'chi' => self::chi($v, $attori),
            'dove' => self::dove($v, $armadi, $vani),
            'errore' => $v->error_code,
            'ip' => $v->ip,
            'contesto' => self::contesto($v),
            'hash' => substr((string) $v->hash, 0, 8),
        ]);
    }

    /** L'azione, detta in italiano. Un `action` è un codice: qui diventa una frase. */
    private static function frase(string $azione): string
    {
        return match ($azione) {
            'auth.login' => 'ha fatto login',
            'auth.logout' => 'ha fatto logout',
            'auth.mfa.enabled' => 'ha attivato la verifica in due passaggi',
            'auth.mfa.disabled' => 'ha disattivato la verifica in due passaggi',
            'auth.mfa.confirm' => 'ha provato a confermare un codice MFA',
            'cabinet.created' => 'ha creato un armadio',
            'cabinet.updated' => 'ha modificato un armadio',
            'cabinet.open_all' => 'HA APERTO TUTTI I VANI',
            'locker.open' => 'ha aperto un vano',
            'locker.opened' => 'il vano si è aperto',
            'locker.closed' => 'lo sportello è stato richiuso',
            'locker.error' => 'la serratura non ha risposto',
            'locker.out_of_service' => 'ha messo un vano fuori servizio',
            'locker.in_service' => 'ha rimesso un vano in servizio',
            'session.created' => 'un cliente ha preso un vano',
            'session.paid' => 'un cliente ha pagato',
            'session.reopened' => 'un cliente ha riaperto il proprio vano',
            'session.checkout' => 'un cliente ha avviato la riconsegna',
            'session.closed' => 'il vano è tornato libero',
            'session.expired' => 'una sessione è scaduta',
            'device.registered' => 'ha registrato un chiosco',
            'device.attached' => 'ha legato un chiosco a un armadio',
            'device.activated' => 'ha attivato un chiosco',
            'device.revoked' => 'ha revocato un chiosco',
            'device.credentials_collected' => 'un chiosco ha ritirato le sue credenziali',
            'user.password_reset_sent' => 'ha mandato un link di reset password',
            default => $azione,
        };
    }

    /** @param  Collection<string, User>  $attori */
    private static function chi(AuditLog $v, Collection $attori): string
    {
        // ⚠️ Un chiosco NON è una persona: quando il registro dice "il chiosco", vuol dire che
        // il fatto è arrivato dal **mondo fisico** — non che qualcuno l'ha cliccato.
        if ($v->actor_type !== 'user') {
            return match ($v->actor_type) {
                'device' => 'il chiosco',
                'system' => 'il sistema',
                'webhook' => 'un webhook',
                default => $v->actor_type,
            };
        }

        $utente = $v->actor_id !== null ? $attori->get($v->actor_id) : null;

        $nome = $utente instanceof User
            ? ($utente->username ?? $utente->email)
            : 'utente sconosciuto';

        return $v->actor_role !== null ? "{$nome} ({$v->actor_role})" : $nome;
    }

    /**
     * @param  Collection<string, Cabinet>  $armadi
     * @param  Collection<string, Locker>  $vani
     */
    private static function dove(AuditLog $v, Collection $armadi, Collection $vani): ?string
    {
        $pezzi = [];

        if ($v->locker_id !== null && ($vano = $vani->get((string) $v->locker_id)) !== null) {
            $pezzi[] = "vano {$vano->number}";
        }

        if ($v->cabinet_id !== null && ($armadio = $armadi->get((string) $v->cabinet_id)) !== null) {
            $pezzi[] = "armadio {$armadio->code}";
        }

        return $pezzi === [] ? null : implode(' · ', $pezzi);
    }

    /**
     * Il contesto, appiattito in coppie `chiave => valore` gia' stringhe.
     *
     * ⚠️ Torna un ARRAY, non una stringa: la vista deve poter colorare la chiave e il valore in
     * modo diverso. Una stringa gia' composta obbligherebbe la Blade a rispaccarla — e a quel
     * punto la formattazione vivrebbe in due posti.
     *
     * @return array<string, string>
     */
    private static function contesto(AuditLog $v): array
    {
        $ctx = $v->context;

        // Gli id li abbiamo gia' trasformati in "vano 3 · armadio G1": ripeterli qui sarebbe
        // esattamente il rumore che questa pagina esiste per togliere.
        unset($ctx['cabinet_id'], $ctx['locker_id']);

        $pulito = [];

        foreach ($ctx as $chiave => $valore) {
            $pulito[(string) $chiave] = is_scalar($valore)
                ? (string) $valore
                : (string) json_encode($valore);
        }

        return $pulito;
    }
}

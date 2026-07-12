<?php

namespace App\Domain\Cabinet\Services;

use App\Domain\Audit\AuditLogger;
use App\Models\Cabinet;
use Illuminate\Support\Facades\DB;

/**
 * Creazione di un armadio e generazione dei suoi vani.
 *
 * L'armadio non serve a niente senza vani, e i vani non si creano a mano uno per uno: si
 * generano dalla mappa RS-485 della scheda serrature.
 */
final class CabinetService
{
    /** Quanti canali ha una scheda serrature, se il tenant non dice altro. */
    private const DEFAULT_CHANNELS_PER_BOARD = 16;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Crea l'armadio e genera `$lockerCount` vani numerati da 1.
     *
     * La mappa (board, canale) e' derivata dal numero del vano: i primi N vani stanno sulla
     * scheda 1, i successivi N sulla scheda 2, e cosi' via. E' la topologia standard di una
     * catena RS-485.
     *
     * ⚠️ Questa e' la mappa che il database CREDE. Che corrisponda al cablaggio reale e'
     * un'altra faccenda, e si verifica un vano alla volta col dispositivo in mano (FH):
     * un cablaggio invertito qui significa aprire l'armadietto sbagliato.
     *
     * @param  array{name: string, code: string, site_id?: string|null, settings?: array<string, mixed>}  $attributes
     */
    public function create(array $attributes, int $lockerCount): Cabinet
    {
        $channelsPerBoard = (int) ($attributes['settings']['channels_per_board']
            ?? self::DEFAULT_CHANNELS_PER_BOARD);

        return DB::transaction(function () use ($attributes, $lockerCount, $channelsPerBoard): Cabinet {
            $cabinet = Cabinet::create($attributes);

            $lockers = [];

            for ($number = 1; $number <= $lockerCount; $number++) {
                $lockers[] = [
                    'number' => $number,
                    'board_address' => intdiv($number - 1, $channelsPerBoard) + 1,
                    'channel' => (($number - 1) % $channelsPerBoard) + 1,
                    'status' => 'free',
                ];
            }

            $cabinet->lockers()->createMany($lockers);

            $this->audit->log('cabinet.created', [
                'cabinet_id' => $cabinet->id,
                'context' => ['code' => $cabinet->code, 'lockers' => $lockerCount],
            ]);

            return $cabinet->load('lockers');
        });
    }
}

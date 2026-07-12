<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Cabinet\Services\CabinetService;
use App\Http\Requests\StoreCabinetRequest;
use App\Http\Requests\UpdateCabinetRequest;
use App\Http\Resources\CabinetResource;
use App\Http\Resources\LockerResource;
use App\Models\Cabinet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Armadi (piano §10).
 *
 * ⚠️ Da nessuna parte, qui dentro, si filtra per `tenant_id`. Non e' una dimenticanza: lo
 * fanno gia' il global scope e la RLS. Un armadio di un altro tenant non esiste, per questo
 * controller — e il route-model binding restituisce **404**, non 403: non si conferma
 * nemmeno che quell'armadio esista da qualche parte.
 */
final class CabinetController
{
    use AuthorizesRequests;

    public function __construct(private readonly CabinetService $cabinets) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Cabinet::class);

        $cabinets = Cabinet::query()
            ->withCount('lockers')
            ->with('device')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('site_id'), fn ($q) => $q->where('site_id', $request->string('site_id')))
            ->orderBy('code')
            ->paginate((int) $request->integer('per_page', 25));

        return CabinetResource::collection($cabinets);
    }

    public function show(Cabinet $cabinet): CabinetResource
    {
        $this->authorize('view', $cabinet);

        return new CabinetResource($cabinet->load('device')->loadCount('lockers'));
    }

    public function store(StoreCabinetRequest $request): JsonResponse
    {
        $this->authorize('create', Cabinet::class);

        $data = $request->validated();
        $lockerCount = (int) $data['lockers'];
        unset($data['lockers']);

        /** @var array{name: string, code: string, site_id?: string|null, settings?: array<string, mixed>} $data */
        $cabinet = $this->cabinets->create($data, $lockerCount);

        return (new CabinetResource($cabinet))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateCabinetRequest $request, Cabinet $cabinet): CabinetResource
    {
        $this->authorize('update', $cabinet);

        $cabinet->update($request->validated());

        return new CabinetResource($cabinet->load('device')->loadCount('lockers'));
    }

    /** Lo stato di OGNI vano dell'armadio: e' la vista che serve al pannello. */
    public function lockers(Cabinet $cabinet): AnonymousResourceCollection
    {
        $this->authorize('view', $cabinet);

        return LockerResource::collection(
            $cabinet->lockers()->orderBy('number')->get()
        );
    }
}

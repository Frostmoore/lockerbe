<?php

namespace Database\Factories;

use App\Models\Cabinet;
use App\Models\Locker;
use App\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    protected $model = Session::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => 'created',
            'amount_cents' => 500,
            'currency' => 'EUR',
            'reserved_until' => now()->addMinutes(10),
            'expires_at' => now()->addHours(8),
            'meta' => [],
        ];
    }

    public function forLocker(Cabinet $cabinet, Locker $locker): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $cabinet->tenant_id,
            'cabinet_id' => $cabinet->id,
            'locker_id' => $locker->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => 'active', 'paid_at' => now()]);
    }

    /** Prenotazione scaduta: il cliente non ha mai pagato. */
    public function reservationExpired(): static
    {
        return $this->state(fn (): array => [
            'status' => 'created',
            'reserved_until' => now()->subMinutes(5),
        ]);
    }
}

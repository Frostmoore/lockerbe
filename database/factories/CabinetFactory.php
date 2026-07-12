<?php

namespace Database\Factories;

use App\Models\Cabinet;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cabinet>
 */
class CabinetFactory extends Factory
{
    protected $model = Cabinet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Armadio '.fake()->unique()->numberBetween(1, 9999),
            'code' => strtoupper(fake()->unique()->bothify('A-###')),
            'status' => 'offline',
            'settings' => ['channels_per_board' => 16],
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    /** Armadio raggiungibile: heartbeat appena ricevuto. */
    public function online(): static
    {
        return $this->state(fn (): array => [
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    /** Heartbeat vecchio: da F4, un `open` verso questo armadio deve fallire con 409. */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'status' => 'online',
            'last_seen_at' => now()->subHours(3),
        ]);
    }

    public function maintenance(): static
    {
        return $this->state(fn (): array => ['status' => 'maintenance', 'last_seen_at' => now()]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Cabinet;
use App\Models\Command;
use App\Models\Locker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Command>
 */
class CommandFactory extends Factory
{
    protected $model = Command::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'open',
            'reason' => 'admin',
            'status' => 'pending',
            'issued_by_type' => 'system',
            'issued_at' => now(),
            'expires_at' => now()->addSeconds(30),
            'payload' => [],
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

    /** Comando oltre il TTL: non deve essere consegnato MAI. */
    public function stale(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}

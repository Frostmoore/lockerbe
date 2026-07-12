<?php

namespace Database\Factories;

use App\Models\Cabinet;
use App\Models\Locker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Locker>
 */
class LockerFactory extends Factory
{
    protected $model = Locker::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 512);

        return [
            'number' => $number,
            'board_address' => intdiv($number - 1, 16) + 1,
            'channel' => (($number - 1) % 16) + 1,
            'status' => 'free',
        ];
    }

    public function forCabinet(Cabinet $cabinet): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $cabinet->tenant_id,
            'cabinet_id' => $cabinet->id,
        ]);
    }

    public function occupied(): static
    {
        return $this->state(fn (): array => ['status' => 'occupied']);
    }

    public function outOfService(): static
    {
        return $this->state(fn (): array => ['status' => 'out_of_service']);
    }
}

<?php

namespace Database\Factories;

use App\Models\Cabinet;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'serial' => strtoupper(fake()->unique()->bothify('FCV#########')),
            'model' => 'VF203_V12',
            'mqtt_client_id' => 'dev-'.fake()->unique()->bothify('??######'),
            'status' => 'provisioned',
        ];
    }

    public function forCabinet(Cabinet $cabinet): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $cabinet->tenant_id,
            'cabinet_id' => $cabinet->id,
        ]);
    }
}

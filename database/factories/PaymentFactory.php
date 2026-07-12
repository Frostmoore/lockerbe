<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'mock',
            'provider_ref' => 'mock_'.Str::uuid7()->toString(),
            'amount_cents' => 500,
            'currency' => 'EUR',
            'status' => 'created',
            'payload' => [],
        ];
    }

    public function forSession(Session $session): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $session->tenant_id,
            'session_id' => $session->id,
            'amount_cents' => $session->amount_cents,
        ]);
    }
}

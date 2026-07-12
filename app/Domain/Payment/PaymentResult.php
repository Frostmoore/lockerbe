<?php

namespace App\Domain\Payment;

/**
 * L'esito che il provider ci comunica.
 *
 * @property-read array<string, mixed> $payload
 */
final readonly class PaymentResult
{
    /**
     * @param  'confirmed'|'failed'  $status
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $providerRef,
        public string $status,
        public array $payload = [],
    ) {}

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}

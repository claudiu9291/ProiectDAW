<?php
namespace App\Contracts;

interface PaymentGatewayInterface {
    public function createCheckoutSession(float $amount, string $currency, array $metadata): string;
}

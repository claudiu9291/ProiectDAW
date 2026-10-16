<?php
namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use Stripe\StripeClient;

class StripePaymentService implements PaymentGatewayInterface {
    private StripeClient $stripe;

    public function __construct(string $secretKey) {
        $this->stripe = new StripeClient($secretKey);
    }

    public function createCheckoutSession(float $amount, string $currency, array $metadata): string {
        $session = $this->stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => ['name' => 'Produs AgroLocal Direct din Fermă'],
                    'unit_amount' => (int) round($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'metadata' => $metadata,
            'success_url' => 'http://localhost:8000/?status=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'http://localhost:8000/?status=cancel',
        ]);
        return $session->url;
    }
}

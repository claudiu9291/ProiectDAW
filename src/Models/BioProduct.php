<?php
namespace App\Models;

use InvalidArgumentException;

class BioProduct extends AbstractProduct {
    private string $certificatEco;
    private float $ecoTaxDiscount = 0.05;

    public function __construct(string $name, float $basePrice, int $stock, string $certificatEco) {
        parent::__construct($name, $basePrice, $stock);
        if (!preg_match('/^RO-ECO-\d{3,6}$/', $certificatEco)) {
            throw new InvalidArgumentException("Format certificat BIO invalid (Ex: RO-ECO-123).");
        }
        $this->certificatEco = $certificatEco;
    }

    public function calculateFinalPrice(): float {
        return round($this->basePrice * (1 - $this->ecoTaxDiscount), 2);
    }

    public function getCertificatEco(): string {
        return $this->certificatEco;
    }
}

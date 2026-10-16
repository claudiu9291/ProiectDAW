<?php
namespace App\Models;

use InvalidArgumentException;

abstract class AbstractProduct {
    protected string $name;
    protected float $basePrice;
    protected int $stock;

    public function __construct(string $name, float $basePrice, int $stock) {
        $this->setName($name);
        $this->setBasePrice($basePrice);
        $this->setStock($stock);
    }

    public function setName(string $name): void {
        $clean = trim(htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
        if (strlen($clean) < 3 || strlen($clean) > 100) {
            throw new InvalidArgumentException("Numele produsului trebuie să aibă între 3 și 100 de caractere.");
        }
        $this->name = $clean;
    }

    public function setBasePrice(float $price): void {
        if ($price <= 0) {
            throw new InvalidArgumentException("Prețul trebuie să fie strict pozitiv.");
        }
        $this->basePrice = $price;
    }

    public function setStock(int $stock): void {
        if ($stock < 0) {
            throw new InvalidArgumentException("Stocul nu poate fi negativ.");
        }
        $this->stock = $stock;
    }

    public function getName(): string { return $this->name; }
    public function getBasePrice(): float { return $this->basePrice; }
    public function getStock(): int { return $this->stock; }

    abstract public function calculateFinalPrice(): float;
}

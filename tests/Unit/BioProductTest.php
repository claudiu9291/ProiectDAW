<?php
namespace Tests\Unit;

use App\Models\BioProduct;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BioProductTest extends TestCase {
    public function testCalculateFinalPriceAppliesEcoDiscount(): void {
        $produs = new BioProduct("Roșii Inimă de Bou", 20.00, 50, "RO-ECO-101");
        $this->assertSame(19.00, $produs->calculateFinalPrice());
    }

    public function testInvalidEcoCertificateThrowsException(): void {
        $this->expectException(InvalidArgumentException::class);
        new BioProduct("Brânză de Burduf", 45.00, 10, "CERT-INVALID");
    }
}

<?php
namespace Tests\Integration;

use App\Config\Database;
use App\Contracts\PaymentGatewayInterface;
use App\Models\BioProduct;
use MongoDB\Database as MongoDatabase;
use PHPUnit\Framework\TestCase;

class CheckoutIntegrationTest extends TestCase {
    private MongoDatabase $db;

    protected function setUp(): void {
        $this->db = Database::getDatabase();
        $this->db->selectCollection('orders_test')->drop();
    }

    public function testOrderCreationInMongoAndPaymentGatewayIntegration(): void {
        $produs = new BioProduct("Mere Ionatan Bio", 10.00, 100, "RO-ECO-999");
        $finalPrice = $produs->calculateFinalPrice();

        $paymentGateway = $this->createMock(PaymentGatewayInterface::class);
        $paymentGateway->expects($this->once())
            ->method('createCheckoutSession')
            ->with($finalPrice, 'RON', ['cert' => 'RO-ECO-999'])
            ->willReturn('https://checkout.stripe.com/pay/cs_test_mongo_123');

        $checkoutUrl = $paymentGateway->createCheckoutSession($finalPrice, 'RON', ['cert' => $produs->getCertificatEco()]);

        $ordersCollection = $this->db->selectCollection('orders_test');
        $insertResult = $ordersCollection->insertOne([
            'product_name' => $produs->getName(),
            'total'        => $finalPrice,
            'stripe_url'   => $checkoutUrl,
            'status'       => 'pending'
        ]);

        $order = $ordersCollection->findOne(['_id' => $insertResult->getInsertedId()]);
        $this->assertNotNull($order);
        $this->assertEquals(9.50, (float)$order['total']);
        $this->assertStringStartsWith('https://checkout.stripe.com', $order['stripe_url']);
    }
}

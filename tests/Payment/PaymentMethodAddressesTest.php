<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodAddresses;

class PaymentMethodAddressesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE tl_consho_shop (id INTEGER PRIMARY KEY, paymentMethods BLOB)');
        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type VARCHAR(32), dns VARCHAR(255) DEFAULT '', consho_shop INTEGER DEFAULT 0, sorting INTEGER DEFAULT 0)");

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'paymentMethods' => serialize(['7', '3'])]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'paymentMethods' => serialize(['7'])]);
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'paymentMethods' => serialize(['9'])]);
    }

    public function testDieDomainsDerShopsInDerReihenfolgeDerStartpunkte(): void
    {
        $this->root(10, 'shop-b.example.com', 2, 128);
        $this->root(11, 'Shop-A.example.com', 1, 64);
        $this->root(12, 'shop-a.example.com', 1, 256);
        $this->root(13, 'other.example.com', 3, 32);
        $this->root(14, '', 1, 16);
        $this->connection->insert('tl_page', ['id' => 15, 'type' => 'regular', 'dns' => 'page.example.com', 'consho_shop' => 1, 'sorting' => 1]);

        self::assertSame(['shop-a.example.com', 'shop-b.example.com'], $this->addresses()->domains(7));
        self::assertSame('https://shop-a.example.com/_consho/payment/webhook/7', $this->addresses()->webhookUrl(7));
    }

    public function testOhneShopOderOhneDomainKeineAdresse(): void
    {
        $this->root(10, '', 1, 1);

        self::assertSame([], $this->addresses()->domains(7), 'Startpunkt ohne Domain');
        self::assertSame('', $this->addresses()->webhookUrl(7));
        self::assertSame([], $this->addresses()->domains(42), 'in keinem Shop');
    }

    private function root(int $id, string $dns, int $shop, int $sorting): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'type' => 'root', 'dns' => $dns, 'consho_shop' => $shop, 'sorting' => $sorting]);
    }

    private function addresses(): PaymentMethodAddresses
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters, int $type): string => UrlGeneratorInterface::ABSOLUTE_PATH === $type && 'consho_payment_webhook' === $name ? '/_consho/payment/webhook/'.$parameters['method'] : 'wrong');

        return new PaymentMethodAddresses($this->connection, $router);
    }
}

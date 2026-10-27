<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCountryCoverage;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * E-5, V3: Which countries a shop's shipping methods reach — and loudly,
 * when a ticked delivery country drops out of the checkout because of it.
 */
class ShippingCountryCoverageTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_shipping_method');
    }

    public function testDieVereinigungDerVersandarten(): void
    {
        $this->shop(1, ['1', '2'], ['AT', 'DE', 'CH']);
        $this->method(1, ['AT']);
        $this->method(2, ['de', 'AT']);

        self::assertSame(['AT', 'DE'], $this->coverage()->servedBy(1));
    }

    public function testEineArtOhneLaenderlisteLiefertUeberallHin(): void
    {
        $this->shop(1, ['1', '2'], ['AT', 'CH']);
        $this->method(1, ['AT']);
        $this->method(2, []);

        self::assertNull($this->coverage()->servedBy(1));
        self::assertSame([], $this->coverage()->issues());
    }

    /**
     * Unpublished or never saved (without a tax class) delivers nowhere —
     * and therefore does not count as "everywhere".
     */
    public function testNurAngebotsfaehigeArtenZaehlen(): void
    {
        $this->shop(1, ['1', '2'], ['AT', 'DE']);
        $this->method(1, ['AT']);
        $this->method(2, [], published: false);

        self::assertSame(['AT'], $this->coverage()->servedBy(1));
    }

    public function testOhneVersandartenKeineEinschraenkung(): void
    {
        $this->shop(1, [], ['AT']);

        self::assertNull($this->coverage()->servedBy(1));
    }

    public function testEinLieferlandOhneVersandartIstEineWarnung(): void
    {
        $this->shop(1, ['1'], ['AT', 'DE', 'CH']);
        $this->method(1, ['AT', 'DE']);

        $issues = $this->coverage()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::DeliveryCountryWithoutShipping, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['shop' => 'Wien', 'countries' => 'CH'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    /**
     * @param list<string> $methods
     * @param list<string> $countries
     */
    private function shop(int $id, array $methods, array $countries): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => 'Wien', 'shippingMethods' => serialize($methods), 'deliveryCountries' => serialize($countries)]);
    }

    /**
     * @param list<string> $countries
     */
    private function method(int $id, array $countries, bool $published = true): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => $id, 'published' => $published ? 1 : 0, 'type' => 'flat_rate', 'taxClass' => 1, 'countries' => [] === $countries ? null : serialize($countries)]);
    }

    private function coverage(): ShippingCountryCoverage
    {
        return new ShippingCountryCoverage($this->connection);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ShopMethodsListener;

class ShopMethodsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_shop']['unpublishedMethod'] = 'unveröffentlicht';

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shipping_method', 'tl_consho_payment_method'] as $table) {
            $this->connection->executeStatement("CREATE TABLE $table (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', published INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0)");
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']['tl_consho_shop']);
    }

    public function testBeideCallbacksSindGenauEinmalRegistriert(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(ShopMethodsListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                self::assertSame(ShopMethodsListener::class, $method->getDeclaringClass()->getName(), $method->getName());

                $arguments = $attribute->getArguments();
                $targets[] = $arguments['table'].' '.$arguments['target'].' '.$method->getName();
            }
        }

        sort($targets);

        self::assertSame([
            'tl_consho_shop fields.paymentMethods.options paymentMethods',
            'tl_consho_shop fields.shippingMethods.options shippingMethods',
        ], $targets);
    }

    public function testAlleArtenStehenZurWahlUnveroeffentlichteSindMarkiert(): void
    {
        // Selectable even when unpublished: one sets up the shop before releasing
        // the method. In the checkout it only counts once published.
        $this->connection->insert('tl_consho_shipping_method', ['id' => 3, 'title' => 'Post', 'published' => 1, 'tstamp' => 5]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 4, 'title' => 'Abholung', 'published' => 0, 'tstamp' => 5]);

        self::assertSame([4 => 'Abholung (unveröffentlicht)', 3 => 'Post'], (new ShopMethodsListener($this->connection))->shippingMethods());
    }

    public function testZahlungsartenKommenAusIhrerTabelle(): void
    {
        $this->connection->insert('tl_consho_payment_method', ['id' => 2, 'title' => 'Vorkasse', 'published' => 1, 'tstamp' => 5]);

        self::assertSame([2 => 'Vorkasse'], (new ShopMethodsListener($this->connection))->paymentMethods());
    }

    public function testNieGespeicherteArtStehtNichtZurWahl(): void
    {
        // Opened with "New" and never saved (tstamp 0): no title, no option.
        $this->connection->insert('tl_consho_shipping_method', ['id' => 5, 'title' => '', 'published' => 0, 'tstamp' => 0]);

        self::assertSame([], (new ShopMethodsListener($this->connection))->shippingMethods());
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderItemLabelListener;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * DL4, Spec 6.4: the columns of the order's line list.
 */
class OrderItemLabelListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require __DIR__.'/../../../contao/languages/de/tl_consho_order_item.php';
        $GLOBALS['TL_CONFIG']['dateFormat'] = 'd.m.Y';
        $GLOBALS['TL_LANGUAGE'] = 'de';

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_entitlement');
        $this->connection->insert('tl_consho_order', ['id' => 4, 'orderNumber' => 'WEB-4', 'grandTotal' => '50', 'currency' => 'EUR']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_CONFIG']['dateFormat'], $GLOBALS['TL_LANGUAGE']);
    }

    /**
     * The list read "Beratung × 1.0000 = 12.5000": the stored decimals, raw.
     */
    public function testBetraegeAlsGeldMengeOhneNachkommanullen(): void
    {
        $columns = $this->listener()(['pid' => 4, 'lineType' => 'product', 'kind' => 'physical', 'productName' => 'Salz', 'quantity' => '2.0000', 'unitPrice' => '7.4167', 'taxRate' => '20.0000', 'lineTotal' => '17.8000', 'performedAt' => null], '');

        // The icon alone, named in its title.
        self::assertSame('<span class="consho-signal consho-signal--kind consho-signal--kind-physical" title="Ware"></span>', $columns[0]);
        self::assertSame('Salz', $columns[1]);
        self::assertSame('2', $columns[2]);
        // Gross like the line total, not the stored net 7,42 next to it.
        self::assertSame("8,90\u{a0}€", $columns[3]);
        self::assertSame('20 %', $columns[4]);
        self::assertSame("17,80\u{a0}€", $columns[5]);
        self::assertSame('', $columns[6]);
    }

    public function testDieVersandzeileNenntIhreArt(): void
    {
        $columns = $this->listener()(['pid' => 4, 'lineType' => 'shipping', 'kind' => '', 'productName' => 'Paket', 'quantity' => '1.0000', 'unitPrice' => '4.9000', 'taxRate' => '20.0000', 'lineTotal' => '4.9000'], '');

        self::assertSame('Versand', $columns[0]);
        self::assertSame('', $columns[6]);
    }

    /**
     * The button appears only after the release; until then the line says when.
     */
    public function testNichtFreigeschalteteLeistungNenntDasDatum(): void
    {
        $release = $this->createStub(DigitalRelease::class);
        $release->method('dueAt')->willReturn(mktime(12, 0, 0, 11, 26, 2099));

        $listener = $this->listener($release);
        $row = ['id' => 9, 'pid' => 4, 'lineType' => 'product', 'kind' => 'service', 'productName' => 'Kurs', 'quantity' => '1', 'unitPrice' => '50', 'taxRate' => '20', 'lineTotal' => '50', 'performedAt' => null];

        self::assertStringContainsString('title="Dienstleistung"', $listener($row, '')[0]);
        self::assertSame('Freischaltung am 26.11.2099', $listener($row, '')[6]);

        // Released: "Leistung erbracht" is in the delivery list.
        $this->connection->insert('tl_consho_entitlement', ['pid' => 4, 'orderItem' => 9]);
        self::assertSame('freigeschaltet — „Leistung erbracht“ unter Lieferungen', $listener($row, '')[6]);
    }

    public function testErbrachteLeistungNenntDasDatum(): void
    {
        $columns = $this->listener()(['pid' => 4, 'lineType' => 'product', 'kind' => 'service', 'productName' => 'Kurs', 'quantity' => '1', 'unitPrice' => '50', 'taxRate' => '20', 'lineTotal' => '50', 'performedAt' => mktime(12, 0, 0, 3, 2, 2099)], '');

        self::assertSame('erbracht am 02.03.2099', $columns[6]);
    }

    private function listener(DigitalRelease|null $release = null): OrderItemLabelListener
    {
        return new OrderItemLabelListener($this->connection, $release ?? $this->createStub(DigitalRelease::class), new MoneyFormatter());
    }
}

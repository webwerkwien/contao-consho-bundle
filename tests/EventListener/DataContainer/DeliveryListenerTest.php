<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DeliveryListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Die Masken der Lieferung (D-1, Z5).
 */
final class DeliveryListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        // orderNumber is unique in the DCA, as every real order has one.
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '60']);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-8', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Wanderstock', 'sku' => 'WS-1', 'quantity' => '2']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'productName' => 'Rucksack', 'quantity' => '1']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => 7, 'productName' => 'Paket', 'quantity' => '1', 'lineType' => 'shipping']);
        $this->connection->insert('tl_consho_order_item', ['id' => 4, 'pid' => 8, 'productName' => 'Fremd', 'quantity' => '1']);
    }

    public function testEineNeueLieferungTraegtWasNochOffenIst(): void
    {
        // Ein Wanderstock ist schon unterwegs.
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7, 'reportedAt' => 100]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);
        $this->connection->insert('tl_consho_delivery', ['id' => 2, 'pid' => 7]);

        $this->listener()->prefill('tl_consho_delivery', 2, ['pid' => 7], $this->createStub(DataContainer::class));

        $items = $this->connection->fetchAllKeyValue('SELECT orderItem, quantity FROM tl_consho_delivery_item WHERE pid = 2 ORDER BY orderItem');

        self::assertSame([1, 2], array_keys($items), 'die Versandzeile nie');
        self::assertEquals(1, $items[1]);
        self::assertEquals(1, $items[2]);
        self::assertGreaterThan(0, (int) $this->connection->fetchOne('SELECT shippedAt FROM tl_consho_delivery WHERE id = 2'));
    }

    /**
     * Spec D, offene Punkte (Review 1, K3): Eine zweite, noch nicht gemeldete
     * Lieferung bekam wieder die vollen offenen Mengen und scheiterte beim
     * Melden der ersten mit „mehr als offen". Jetzt trägt sie, was die
     * ungemeldeten anderen übrig lassen — und nichts, wenn nichts übrig ist.
     */
    public function testEineZweiteUngemeldeteLieferungTraegtNurDenRest(): void
    {
        // Lieferung 1: noch nicht gemeldet, ein Wanderstock und der Rucksack.
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 2, 'quantity' => '1']);
        $this->connection->insert('tl_consho_delivery', ['id' => 2, 'pid' => 7]);

        $this->listener()->prefill('tl_consho_delivery', 2, ['pid' => 7], $this->createStub(DataContainer::class));

        $items = $this->connection->fetchAllKeyValue('SELECT orderItem, quantity FROM tl_consho_delivery_item WHERE pid = 2 ORDER BY orderItem');
        self::assertSame([1], array_keys($items), 'der Rucksack steckt schon in Lieferung 1');
        self::assertEquals(1, $items[1]);

        $this->connection->insert('tl_consho_delivery', ['id' => 3, 'pid' => 7]);
        $this->listener()->prefill('tl_consho_delivery', 3, ['pid' => 7], $this->createStub(DataContainer::class));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_delivery_item WHERE pid = 3'), 'alles verplant: leer');
    }

    public function testZurWahlStehenNurDieWarenDerEigenenBestellung(): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7]);

        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 9, 'pid' => 1]);

        self::assertSame([1 => 'Wanderstock (WS-1)', 2 => 'Rucksack'], $this->listener()->orderItemOptions($dc));
    }

    /**
     * F-4, F17: Digitale Zeilen werden freigegeben, nicht verpackt — eine
     * Paketlieferung schlägt sie weder vor noch bietet sie zur Wahl an.
     */
    public function testDigitaleZeilenGehenNichtInsPaket(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 5, 'pid' => 7, 'productName' => 'E-Book', 'quantity' => '1', 'kind' => 'digital']);
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7]);

        $this->listener()->prefill('tl_consho_delivery', 1, ['pid' => 7], $this->createStub(DataContainer::class));

        self::assertSame([1, 2], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT orderItem FROM tl_consho_delivery_item WHERE pid = 1 ORDER BY orderItem')));

        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 9, 'pid' => 1]);

        self::assertArrayNotHasKey(5, $this->listener()->orderItemOptions($dc));
    }

    private function listener(): DeliveryListener
    {
        return new DeliveryListener(
            $this->connection,
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            new MoneyFormatter(),
        );
    }
}

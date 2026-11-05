<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderLabelListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * The payment signal of the order list names the state, not the colour.
 * An order that was cancelled and refunded read "Payment: open", because
 * "refunded" is grey like "open".
 */
final class OrderLabelListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        unset($GLOBALS['TL_LANG']);
        // The order date in the row is formatted as well.
        $GLOBALS['TL_CONFIG']['dateFormat'] = 'd.m.Y';
        include __DIR__.'/../../../contao/languages/de/tl_consho_order.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_CONFIG']['dateFormat']);
    }

    public function testStorniertUndErstattetHeisstErstattet(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '29.3000', 'cancelledAt' => 1]);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '19.0000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-19.0000']);

        $signals = $this->listener()(['id' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '29.3000', 'currency' => 'EUR'], '')[1];

        self::assertStringContainsString('title="Zahlung: erstattet"', $signals);
        self::assertStringNotContainsString('Zahlung: offen', $signals);
    }

    /**
     * A service-only order is not waiting for a parcel: the delivery list
     * refuses one, so the signal must not say "Lieferung: offen".
     */
    public function testOffeneLeistungHeisstNichtLieferung(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 2, 'orderNumber' => 'WEB-2', 'grandTotal' => '50']);
        $this->connection->insert('tl_consho_order_item', ['id' => 5, 'pid' => 2, 'productName' => 'Kurs', 'quantity' => '1', 'kind' => 'service']);

        $signals = $this->listener()(['id' => 2, 'orderNumber' => 'WEB-2', 'grandTotal' => '50', 'currency' => 'EUR'], '')[1];

        self::assertStringContainsString('Leistung: noch nicht freigeschaltet', $signals);
        self::assertStringNotContainsString('Lieferung: offen', $signals);
    }

    public function testWareUndLeistungOffenNenntBeide(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 3, 'orderNumber' => 'WEB-3', 'grandTotal' => '60']);
        $this->connection->insert('tl_consho_order_item', ['id' => 6, 'pid' => 3, 'productName' => 'Salz', 'quantity' => '1', 'kind' => 'physical']);
        $this->connection->insert('tl_consho_order_item', ['id' => 7, 'pid' => 3, 'productName' => 'Kurs', 'quantity' => '1', 'kind' => 'service']);

        $columns = $this->listener()(['id' => 3, 'orderNumber' => 'WEB-3', 'grandTotal' => '60', 'currency' => 'EUR'], '');

        self::assertStringContainsString('Lieferung: offen · Leistung: noch nicht freigeschaltet', $columns[1]);
        self::assertSame('<span class="consho-signal consho-signal--kind consho-signal--kind-mixed" title="Gemischt: Ware, Dienstleistung"></span>', $columns[0]);
    }

    public function testDieArtIstEineEigeneSpalte(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order_item', ['id' => 11, 'pid' => 7, 'productName' => 'E-Book', 'quantity' => '1', 'kind' => 'digital']);
        $this->connection->insert('tl_consho_order_item', ['id' => 12, 'pid' => 7, 'productName' => 'Versand', 'quantity' => '1', 'kind' => 'physical', 'lineType' => 'shipping']);

        $columns = $this->listener()(['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '10', 'currency' => 'EUR'], '');

        // The shipping line is no product: digital alone, not a mix.
        self::assertSame('<span class="consho-signal consho-signal--kind consho-signal--kind-digital" title="Digitales"></span>', $columns[0]);
        self::assertStringNotContainsString('consho-signal--kind', $columns[1]);
        self::assertCount(6, $columns);
    }

    public function testVorDemVersandWiderrufenIstNichtVollstaendigGeliefert(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 4, 'orderNumber' => 'WEB-4', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order_item', ['id' => 8, 'pid' => 4, 'productName' => 'Salz', 'quantity' => '1', 'kind' => 'physical']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 4, 'number' => 'WEB-4-W1', 'state' => 'accepted', 'decidedAt' => 100]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 8, 'quantity' => '0', 'acceptedQuantity' => '1']);

        $signals = $this->listener()(['id' => 4, 'orderNumber' => 'WEB-4', 'grandTotal' => '10', 'currency' => 'EUR'], '')[1];

        self::assertStringContainsString('title="Lieferung: nichts mehr offen"', $signals);
    }

    public function testOffeneLeistungNenntDasDatumDerFreischaltung(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderNumber' => 'WEB-5', 'grandTotal' => '50']);
        $this->connection->insert('tl_consho_order_item', ['id' => 9, 'pid' => 5, 'productName' => 'Kurs', 'quantity' => '1', 'kind' => 'service']);
        $dueAt = mktime(0, 0, 0, 1, 12, 2099);

        $release = $this->createStub(DigitalRelease::class);
        $release->method('dueAt')->willReturn($dueAt);

        $signals = $this->listener($release)(['id' => 5, 'orderNumber' => 'WEB-5', 'grandTotal' => '50', 'currency' => 'EUR', 'createdAt' => 1], '')[1];

        self::assertStringContainsString('title="Leistung: wird am 12.01.2099 freigeschaltet"', $signals);
    }

    public function testOhneZahlungWartetDieFreischaltung(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 6, 'orderNumber' => 'WEB-6', 'grandTotal' => '50']);
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 6, 'productName' => 'E-Book', 'quantity' => '1', 'kind' => 'digital']);

        $release = $this->createStub(DigitalRelease::class);
        $release->method('dueAt')->willReturn(null);

        $signals = $this->listener($release)(['id' => 6, 'orderNumber' => 'WEB-6', 'grandTotal' => '50', 'currency' => 'EUR', 'createdAt' => 1], '')[1];

        self::assertStringContainsString('title="Digitales: wartet auf die Zahlung"', $signals);
    }

    public function testJederZahlungszustandHatEinenText(): void
    {
        foreach (PaymentState::cases() as $state) {
            self::assertArrayHasKey($state->value, $GLOBALS['TL_LANG']['tl_consho_order']['signals']['payment'], $state->name);
        }
    }

    private function listener(DigitalRelease|null $release = null): OrderLabelListener
    {
        return new OrderLabelListener($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()), $release);
    }
}

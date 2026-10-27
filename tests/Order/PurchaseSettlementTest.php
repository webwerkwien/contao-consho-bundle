<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PurchaseSettlement;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * WG15: an order over €0 never counted as paid — PaymentRecorded demands a
 * positive claim, and a payment of 0 never comes. An e-book with a 100 %
 * code was therefore never released.
 */
class PurchaseSettlementTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_payment');
    }

    public function testEineBestellungUeberNullIstBezahlt(): void
    {
        $this->order(1, '0.0000');

        $statuses = $this->createMock(OrderStatusAdvancer::class);
        $statuses->expects(self::once())->method('advance')->with(1, OrderEvent::PaymentSettled, 1_800_000_000);

        $this->settlement(statuses: $statuses)->settleIfNothingOpen(1, 3, $this->method(), 1_800_000_000);

        self::assertSame(1_800_000_000, (int) $this->connection->fetchOne('SELECT paymentSettledAt FROM tl_consho_order WHERE id = 1'));
    }

    public function testEineBestellungMitOffenemBetragBleibtOffen(): void
    {
        $this->order(1, '12.0000');

        $statuses = $this->createMock(OrderStatusAdvancer::class);
        $statuses->expects(self::never())->method('advance');

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->expects(self::never())->method('issueOnce');

        self::assertNull($this->settlement($documents, $statuses, DocumentTrigger::OnPayment)->settleIfNothingOpen(1, 3, $this->method(), 1_800_000_000));
        self::assertSame('', (string) $this->connection->fetchOne('SELECT paymentSettledAt FROM tl_consho_order WHERE id = 1'));
    }

    /**
     * What is already paid at the purchase click — later a gift card (WG-5) —
     * counts too: covered is covered, not only "€0".
     */
    public function testEineVollGedeckteBestellungIstBezahlt(): void
    {
        $this->order(1, '12.0000');
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '12.0000', 'method' => 'voucher']);

        $this->settlement()->settleIfNothingOpen(1, 3, $this->method(), 1_800_000_000);

        self::assertSame(1_800_000_000, (int) $this->connection->fetchOne('SELECT paymentSettledAt FROM tl_consho_order WHERE id = 1'));
    }

    public function testDieRechnungBeiZahlungEntstehtMitDemKaufklick(): void
    {
        $this->order(1, '0.0000');

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->method('hasDocument')->willReturn(false);
        $documents->expects(self::once())->method('issueOnce')->with(1, 3, DocumentType::Invoice)->willReturn(44);

        self::assertSame(44, $this->settlement($documents, trigger: DocumentTrigger::OnPayment)->settleIfNothingOpen(1, 3, $this->method(), 1_800_000_000));
    }

    public function testEineRechnungBeimKaufWirdNichtVerdoppelt(): void
    {
        $this->order(1, '0.0000');

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->method('hasDocument')->willReturn(true);
        $documents->expects(self::never())->method('issueOnce');

        self::assertNull($this->settlement($documents, trigger: DocumentTrigger::OnPayment)->settleIfNothingOpen(1, 3, $this->method(), 1_800_000_000));
    }

    public function testEinAndererAusloeserStelltKeineRechnungAus(): void
    {
        $this->order(1, '0.0000');

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->method('hasDocument')->willReturn(false);
        $documents->expects(self::never())->method('issueOnce');

        self::assertNull($this->settlement($documents, trigger: DocumentTrigger::OnShipment)->settleIfNothingOpen(1, 3, $this->method(), 1_800_000_000));
    }

    /**
     * E1: "with gift card" has no handler and no trigger — it is paid with the
     * click, so the invoice is created now; otherwise it would never be created
     * (no handler ever reports a payment).
     */
    public function testMitGutscheinBezahltEntstehtDieRechnungMitDemKaufklick(): void
    {
        $this->order(1, '66.0000');
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '66.0000', 'method' => 'Gutschein …MDSN']);

        $documents = $this->createMock(DocumentIssuerInterface::class);
        $documents->method('hasDocument')->willReturn(false);
        $documents->expects(self::once())->method('issueOnce')->with(1, 3, DocumentType::Invoice)->willReturn(45);

        // Even next to a handler that would say "on shipping": payment method 0 has none.
        self::assertSame(45, $this->settlement($documents, trigger: DocumentTrigger::OnShipment)->settleIfNothingOpen(1, 3, new PaymentMethod(CheckoutCalculator::PAYMENT_BY_VOUCHER, 'voucher', 'Gutschein', []), 1_800_000_000));
    }

    private function order(int $id, string $grandTotal): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'grandTotal' => $grandTotal]);
    }

    private function method(): PaymentMethod
    {
        return new PaymentMethod(6, 'prepayment', 'Vorkasse', []);
    }

    private function settlement(
        DocumentIssuerInterface|null $documents = null,
        OrderStatusAdvancer|null $statuses = null,
        DocumentTrigger $trigger = DocumentTrigger::OnOrder,
    ): PurchaseSettlement {
        $handler = $this->createStub(PaymentHandlerInterface::class);
        $handler->method('documentTrigger')->willReturn($trigger);

        $locator = $this->createStub(ServiceProviderInterface::class);
        $locator->method('has')->willReturn(true);
        $locator->method('get')->willReturn($handler);

        return new PurchaseSettlement(
            $this->connection,
            $documents ?? $this->createStub(DocumentIssuerInterface::class),
            new PaymentHandlers($locator),
            $statuses ?? $this->createStub(OrderStatusAdvancer::class),
        );
    }
}

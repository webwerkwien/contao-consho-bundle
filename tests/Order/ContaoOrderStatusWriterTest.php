<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Order\ContaoOrderStatusWriter;
use Webwerkwien\ContaoConshoBundle\Order\OrderCancellation;
use Webwerkwien\ContaoConshoBundle\Order\StatusFlags;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPurchaseCancellation;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;

/**
 * The automatic status change — here only what a cancellation triggers.
 *
 * Without a test of its own until WG-6: subscription, stock and discount
 * code hung on this place and on the backend form (OrderStatusListener), and
 * only the form was checked. A connection forgotten here would only show up
 * on a cancellation through a payment notification.
 */
class ContaoOrderStatusWriterTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 7]);
    }

    public function testDieStornierungLoestJedenRueckbauEinmalAus(): void
    {
        $subscriptions = $this->createMock(SubscriptionPurchaseCancellation::class);
        $subscriptions->expects(self::once())->method('afterCancellation')->with(7, self::NOW);
        $stock = $this->createMock(StockReturn::class);
        $stock->expects(self::once())->method('afterCancellation')->with(7, self::NOW);
        $discounts = $this->createMock(CartDiscounts::class);
        $discounts->expects(self::once())->method('afterCancellation')->with(7, self::NOW);
        $vouchers = $this->createMock(VoucherReturn::class);
        $vouchers->expects(self::once())->method('afterCancellation')->with(7, self::NOW);

        $writer = new ContaoOrderStatusWriter($this->framework(), $this->connection, $this->createStub(UrlGeneratorInterface::class), new OrderCancellation($this->connection, $subscriptions, $stock, $discounts, $vouchers));

        $writer->write(7, new StatusFlags(9, 1, 'Storniert', isCancelled: true), self::NOW);
        $writer->write(7, new StatusFlags(9, 1, 'Storniert', isCancelled: true), self::NOW + 5);

        self::assertSame(self::NOW, (int) $this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = 7'));
    }

    public function testOhneStornoKeinRueckbau(): void
    {
        $vouchers = $this->createMock(VoucherReturn::class);
        $vouchers->expects(self::never())->method('afterCancellation');

        (new ContaoOrderStatusWriter($this->framework(), $this->connection, $this->createStub(UrlGeneratorInterface::class), new OrderCancellation($this->connection, vouchers: $vouchers)))
            ->write(7, new StatusFlags(3, 1, 'Bezahlt', isPaid: true), self::NOW);
    }

    private function framework(): ContaoFramework
    {
        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('createInstance')->willReturn($this->createStub(Versions::class));

        return $framework;
    }
}

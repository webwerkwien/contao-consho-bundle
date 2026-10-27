<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Setup\NumberRangeSetup;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * D-3: open withdrawals on the start page (F11) and missing number ranges
 * for refunds (F9).
 */
final class WithdrawalIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        unset($GLOBALS['TL_LANG']);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_number_range');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 1]);

        foreach (['order', 'invoice', 'creditNote', 'cancellation'] as $type) {
            $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'type' => $type]);
        }
    }

    public function testOhneOffeneWiderrufeNichts(): void
    {
        $this->withdrawal('W3', 'rejected', 1_790_000_000);
        $this->withdrawal('W4', 'accepted', 1_790_000_000, 1_790_100_000);

        self::assertSame([], $this->issues());
    }

    public function testOffeneWiderrufeMitDerErstenFaelligkeit(): void
    {
        $this->withdrawal('WEB-2', 'accepted', strtotime('2026-09-25 10:00'));
        $this->withdrawal('WEB-1', 'received', strtotime('2026-09-21 10:00'));

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::WithdrawalsOpen, $issues[0]->finding);
        self::assertSame(['shop' => 'Testshop', 'count' => '2', 'numbers' => 'WEB-1, WEB-2', 'due' => '05.10.2026'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    /**
     * WA, A5: Withdrawals without an order — an issue of its own per shop they
     * came in through; they do not appear in the "open withdrawals" issue.
     */
    public function testNichtZugeordneteWiderrufeEinEigenerBefund(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 1, 'number' => 'W-00002', 'state' => 'received', 'receivedAt' => strtotime('2026-09-25 10:00')]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 1, 'number' => 'W-00001', 'state' => 'received', 'receivedAt' => strtotime('2026-09-21 10:00')]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::WithdrawalsUnassigned, $issues[0]->finding);
        self::assertSame(['shop' => 'Testshop', 'count' => '2', 'numbers' => 'W-00001, W-00002', 'due' => '05.10.2026'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
        self::assertTrue($issues[0]->finding->isWarning());

        // Once assigned it is an ordinary open withdrawal.
        $this->connection->update('tl_consho_withdrawal', ['pid' => 7], ['number' => 'W-00001']);

        self::assertSame([ConfigurationFinding::WithdrawalsOpen, ConfigurationFinding::WithdrawalsUnassigned], array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $this->issues()));

        // WA: rejected without an order means done.
        $this->connection->update('tl_consho_withdrawal', ['state' => 'rejected'], ['number' => 'W-00002']);

        self::assertSame([ConfigurationFinding::WithdrawalsOpen], array_map(static fn (ConfigurationIssue $i): ConfigurationFinding => $i->finding, $this->issues()));
    }

    /**
     * WA: A deleted shop does not take its withdrawals with it — the refund is
     * still owed. A general issue, with the old ID.
     */
    public function testWiderrufeEinesGeloeschtenShops(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 9, 'number' => 'W-00003', 'state' => 'received', 'receivedAt' => strtotime('2026-09-21 10:00')]);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'shop' => 9]);
        $this->withdrawal('WEB-8-W1', 'received', strtotime('2026-09-22 10:00'));
        $this->connection->update('tl_consho_withdrawal', ['pid' => 8], ['number' => 'WEB-8-W1']);

        $issues = $this->issues();

        self::assertSame([[ConfigurationFinding::WithdrawalsOpen, '#9', 0], [ConfigurationFinding::WithdrawalsUnassigned, '#9', 0]], array_map(static fn (ConfigurationIssue $i): array => [$i->finding, $i->context['shop'], $i->shopId], $issues));
    }

    public function testFehlenderKreisFuerDieRechnungskorrektur(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'creditNote']);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::RefundRangeMissing, $issues[0]->finding);
        self::assertSame('creditNote', $issues[0]->context['types']);
        // Block I: the setup's button reads the types from this issue.
        self::assertSame(['creditNote'], NumberRangeSetup::missing($issues[0]->shopId, $issues));
    }

    /**
     * D-3: On the start page only default.php is loaded — the issue must take
     * the name from there, not show the key.
     */
    public function testDerBefundNenntDenKreisBeimNamen(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'creditNote']);
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../contao/languages/de/default.php';

        try {
            self::assertSame('Rechnungskorrektur-Nummer', $this->issues()[0]->context['types']);
        } finally {
            unset($GLOBALS['TL_LANG']);
        }
    }

    /**
     * No invoices, no document to correct; no withdrawal period, no refund.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function ohneBedarf(): iterable
    {
        yield 'ohne Rechnungskreis' => ['invoice', 14];
        yield 'ohne Widerrufsfunktion' => ['', 0];
    }

    /**
     * @dataProvider ohneBedarf
     */
    public function testOhneBedarfKeinBefund(string $remove, int $days): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'creditNote']);

        if ('' !== $remove) {
            $this->connection->delete('tl_consho_number_range', ['type' => $remove]);
        }

        $this->connection->update('tl_consho_shop', ['withdrawalDays' => $days], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new WithdrawalIssueSource($this->connection))->issues();
    }

    private function withdrawal(string $number, string $state, int|false $received, int|null $refunded = null): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 7, 'number' => $number, 'state' => $state, 'receivedAt' => (int) $received, 'refundedAt' => $refunded]);
    }
}

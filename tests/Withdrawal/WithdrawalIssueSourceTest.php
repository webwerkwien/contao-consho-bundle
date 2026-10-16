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
 * D-3: offene Widerrufe auf der Startseite (F11) und fehlende Nummernkreise
 * für Erstattungen (F9).
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

    public function testFehlenderKreisFuerDieRechnungskorrektur(): void
    {
        $this->connection->delete('tl_consho_number_range', ['type' => 'creditNote']);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::RefundRangeMissing, $issues[0]->finding);
        self::assertSame('creditNote', $issues[0]->context['types']);
        // Block I: der Knopf der Einrichtung liest die Typen aus diesem Befund.
        self::assertSame(['creditNote'], NumberRangeSetup::missing($issues[0]->shopId, $issues));
    }

    /**
     * Fable-Review D-3, S1: Auf der Startseite ist nur default.php geladen —
     * der Befund muss den Namen daraus nehmen, nicht den Schlüssel zeigen.
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
     * Keine Rechnungen, kein Beleg zu korrigieren; keine Widerrufsfrist,
     * keine Erstattung.
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

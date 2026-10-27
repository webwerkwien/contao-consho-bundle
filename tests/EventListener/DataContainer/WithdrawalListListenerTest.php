<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\WithdrawalListListener;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSignals;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * "Withdrawals" as a menu item of its own (D-3, F11): the same table, flat
 * across all orders — and a label that names the due date.
 */
final class WithdrawalListListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        WithdrawalSchema::create($this->connection);
        // Currency as OrderFactory writes it; the DCA default is empty (the
        // hand-written table defaulted to EUR).
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-00077', 'currency' => 'EUR']);

        $GLOBALS['TL_DCA']['tl_consho_withdrawal'] = [];
        require __DIR__.'/../../../contao/dca/tl_consho_withdrawal.php';
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/de/tl_consho_withdrawal.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_consho_withdrawal'], $GLOBALS['TL_LANG']);
    }

    public function testImMenuepunktFlachUndOhneElternTabelle(): void
    {
        $this->listener('consho_withdrawal')->flatInModule('tl_consho_withdrawal');

        $dca = $GLOBALS['TL_DCA']['tl_consho_withdrawal'];

        self::assertArrayNotHasKey('ptable', $dca['config']);
        self::assertSame(1, $dca['list']['sorting']['mode']);
        self::assertSame(['receivedAt DESC', 'id DESC'], $dca['list']['sorting']['fields']);
        self::assertTrue($dca['fields']['state']['filter']);
    }

    public function testUnterDerBestellungBleibtEsDieKindliste(): void
    {
        $this->listener('consho_order')->flatInModule('tl_consho_withdrawal');

        self::assertSame('tl_consho_order', $GLOBALS['TL_DCA']['tl_consho_withdrawal']['config']['ptable']);
        self::assertSame(4, $GLOBALS['TL_DCA']['tl_consho_withdrawal']['list']['sorting']['mode']);
    }

    public function testAndereTabellenBleibenUnberuehrt(): void
    {
        $GLOBALS['TL_DCA']['tl_other'] = ['config' => ['ptable' => 'x']];

        $this->listener('consho_withdrawal')->flatInModule('tl_other');

        self::assertSame('x', $GLOBALS['TL_DCA']['tl_other']['config']['ptable']);
        unset($GLOBALS['TL_DCA']['tl_other']);
    }

    public function testDasLabelNenntBestellungStandUndFaelligkeit(): void
    {
        $received = strtotime('2026-09-21 16:13');

        $label = $this->listener('consho_withdrawal')->label(['number' => 'WEB-00077-W1', 'pid' => 7, 'receivedAt' => $received, 'state' => 'accepted', 'refundedAt' => null]);

        self::assertStringContainsString('WEB-00077-W1 — WEB-00077 — 21.09.2026 16:13 — angenommen', $label);
        self::assertStringContainsString('Erstattung fällig bis 05.10.2026', $label);
    }

    /**
     * F12: the icons stand for return shipping instead of shipping, refund
     * instead of payment, and total. In the menu item, columns as for the
     * orders, the three icons in front.
     */
    public function testImMenuepunktSpaltenMitDenDreiSymbolen(): void
    {
        $listener = $this->listener('consho_withdrawal');
        $listener->flatInModule('tl_consho_withdrawal');

        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_withdrawal']['list']['label']['showColumns']);

        $this->connection->insert('tl_consho_withdrawal', ['id' => 5, 'pid' => 7, 'number' => 'WEB-00077-W1', 'state' => 'accepted', 'receivedAt' => strtotime('2026-09-21 16:13'), 'refundedAt' => 1_790_100_000, 'refundAmount' => '8.9000', 'name' => 'Erika <b>']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 5, 'orderItem' => 1, 'acceptedQuantity' => '1', 'returnedQuantity' => '1']);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal WHERE id = 5');
        self::assertIsArray($row);

        $cells = $listener->label($row);

        self::assertIsArray($cells);
        self::assertCount(7, $cells, 'eine Zelle je Spalte');
        self::assertStringContainsString('consho-signal--undo consho-signal--done', $cells[0]);
        self::assertStringContainsString('consho-signal--copy consho-signal--done', $cells[0], 'alles zurück');
        self::assertStringContainsString('consho-signal--sync consho-signal--done', $cells[0], 'erstattet');
        self::assertStringContainsString('title="Rückzahlung: gebucht"', $cells[0]);
        self::assertSame(['WEB-00077-W1', 'WEB-00077', 'Erika &lt;b&gt;', '21.09.2026 16:13', '', '8,90 EUR'], \array_slice($cells, 1));
    }

    public function testUeberfaelligIstRotUndFett(): void
    {
        $listener = $this->listener('consho_withdrawal');
        $listener->flatInModule('tl_consho_withdrawal');

        $cells = $listener->label(['id' => 9, 'number' => 'W', 'pid' => 7, 'state' => 'received', 'receivedAt' => strtotime('2026-01-01 10:00'), 'refundedAt' => null, 'refundAmount' => null, 'name' => '']);

        self::assertIsArray($cells);
        self::assertStringContainsString('consho-signal--undo consho-signal--attention', $cells[0]);
        self::assertSame('<strong>15.01.2026</strong>', $cells[5]);
    }

    /**
     * WA: without an order it says so in the order column, where one looks for
     * the order; received after the end of the window is noted in both views.
     */
    public function testNichtZugeordnetUndNachFensterende(): void
    {
        // Under the order first: flatInModule switches to columns globally.
        $line = $this->listener('consho_order')->label(['number' => 'W-00009', 'pid' => 7, 'receivedAt' => 1_790_000_000, 'state' => 'received', 'refundedAt' => null, 'afterWindow' => 1]);

        self::assertIsString($line);
        self::assertStringEndsWith(' — '.$GLOBALS['TL_LANG']['tl_consho_withdrawal']['afterWindow'][0], $line);

        $listener = $this->listener('consho_withdrawal');
        $listener->flatInModule('tl_consho_withdrawal');

        $cells = $listener->label(['id' => 9, 'number' => 'W-00009', 'pid' => 0, 'state' => 'received', 'receivedAt' => 1_790_000_000, 'refundedAt' => null, 'refundAmount' => null, 'name' => 'E', 'afterWindow' => 0]);

        self::assertIsArray($cells);
        self::assertSame('<em>nicht zugeordnet</em>', $cells[2]);

        $late = $listener->label(['id' => 9, 'number' => 'W-00009', 'pid' => 7, 'state' => 'received', 'receivedAt' => 1_790_000_000, 'refundedAt' => null, 'refundAmount' => null, 'name' => 'E', 'afterWindow' => 1]);

        self::assertIsArray($late);
        self::assertStringStartsWith('WEB-00077 <span class="tl_gray"', $late[2]);
        self::assertStringContainsString($GLOBALS['TL_LANG']['tl_consho_withdrawal']['afterWindow'][0], $late[2]);
    }

    public function testErstattetOderAbgelehntOhneFaelligkeit(): void
    {
        $listener = $this->listener('consho_withdrawal');

        self::assertStringContainsString('erstattet am', $listener->label(['number' => 'W', 'pid' => 7, 'receivedAt' => 1_790_000_000, 'state' => 'accepted', 'refundedAt' => 1_790_100_000]));
        self::assertStringNotContainsString('fällig', $listener->label(['number' => 'W', 'pid' => 7, 'receivedAt' => 1_790_000_000, 'state' => 'rejected', 'refundedAt' => null]));
    }

    private function listener(string $module): WithdrawalListListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/contao?do='.$module));

        return new WithdrawalListListener($this->connection, $requests, new WithdrawalSignals($this->connection));
    }
}

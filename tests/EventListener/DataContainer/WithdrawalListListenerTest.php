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
 * „Widerrufe" als eigener Menüpunkt (D-3, F11): dieselbe Tabelle, flach über
 * alle Bestellungen — und ein Label, das die Fälligkeit nennt.
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
     * F12 — Michael: „die icons machen sinn statt Versand Rückversand, statt
     * zahlung Rückzahlung und gesamt". Im Menüpunkt Spalten wie bei den
     * Bestellungen, vorne die drei Symbole.
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

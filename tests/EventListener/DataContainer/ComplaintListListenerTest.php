<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ComplaintListListener;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * "Reklamationen" as a menu item (block Reklamation, R10): the same table,
 * flat over all orders, closed for new records, in columns.
 */
final class ComplaintListListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_complaint', 'tl_consho_complaint_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-00077', 'firstname' => 'Erika', 'lastname' => 'Example']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Herb vinegar']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'productName' => 'Herb salt']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 4, 'orderItem' => 1, 'quantity' => '1.0000']);
        $this->connection->insert('tl_consho_complaint_item', ['pid' => 4, 'orderItem' => 2, 'quantity' => '2.0000']);

        $GLOBALS['TL_DCA']['tl_consho_complaint'] = [];
        require __DIR__.'/../../../contao/dca/tl_consho_complaint.php';
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/de/tl_consho_complaint.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_consho_complaint'], $GLOBALS['TL_LANG']);
    }

    public function testFlatClosedAndInColumnsInTheMenuItem(): void
    {
        $this->listener('consho_complaint')->flatInModule('tl_consho_complaint');

        $dca = $GLOBALS['TL_DCA']['tl_consho_complaint'];
        self::assertArrayNotHasKey('ptable', $dca['config']);
        self::assertTrue($dca['config']['closed'], 'a complaint is recorded under its order');
        self::assertSame(1, $dca['list']['sorting']['mode']);
        self::assertSame(['receivedAt DESC', 'id DESC'], $dca['list']['sorting']['fields']);
        self::assertTrue($dca['list']['label']['showColumns']);
        self::assertSame(['state', 'number', 'pid', 'customer', 'receivedAt', 'remedy', 'lines', 'resolvedAt'], $dca['list']['label']['fields']);
        self::assertTrue($dca['fields']['state']['filter']);
        self::assertTrue($dca['fields']['remedy']['filter']);
    }

    public function testUnderTheOrderItStaysTheOrdersColumnList(): void
    {
        $this->listener('consho_order')->flatInModule('tl_consho_complaint');

        $dca = $GLOBALS['TL_DCA']['tl_consho_complaint'];
        self::assertSame('tl_consho_order', $dca['config']['ptable'], 'the ptable keeps the list to this order');
        self::assertArrayNotHasKey('closed', $dca['config']);
        self::assertSame(1, $dca['list']['sorting']['mode']);
        self::assertSame('number', $dca['list']['sorting']['defaultSearchField'], 'names the record in the headline');
        self::assertSame(['state', 'number', 'receivedAt', 'note', 'lines', 'resolvedAt'], $dca['list']['label']['fields']);
    }

    public function testOtherTablesStayUntouched(): void
    {
        $GLOBALS['TL_DCA']['tl_other'] = ['config' => ['ptable' => 'x']];

        $this->listener('consho_complaint')->flatInModule('tl_other');

        self::assertSame('x', $GLOBALS['TL_DCA']['tl_other']['config']['ptable']);
        unset($GLOBALS['TL_DCA']['tl_other']);
    }

    public function testColumns(): void
    {
        $listener = $this->listener('consho_complaint');
        $listener->flatInModule('tl_consho_complaint');

        $cells = $listener->label($this->row(['state' => 'open']));

        self::assertSame('<span class="consho-signal consho-signal--complaint consho-signal--attention" title="offen"></span>', $cells[0], 'open waits for the operator: red, the icon alone');
        self::assertSame('WEB-00077-R1', $cells[1]);
        self::assertSame('WEB-00077', $cells[2]);
        self::assertSame('Erika Example', $cells[3]);
        self::assertSame('03.11.2026', $cells[4]);
        self::assertSame('', $cells[5], 'no remedy yet');
        self::assertSame('1 × Herb vinegar, 2 × Herb salt', $cells[6]);
        self::assertSame('', $cells[7]);
    }

    public function testStateColours(): void
    {
        $listener = $this->listener('consho_complaint');
        $listener->flatInModule('tl_consho_complaint');

        foreach (['inProgress' => 'progress', 'resolved' => 'done', 'rejected' => 'open'] as $state => $colour) {
            self::assertStringContainsString('consho-signal--'.$colour.'"', $listener->label($this->row(['state' => $state]))[0], $state);
        }
    }

    public function testColumnsOfAResolvedComplaintWithARemedy(): void
    {
        $listener = $this->listener('consho_complaint');
        $listener->flatInModule('tl_consho_complaint');

        $cells = $listener->label($this->row(['id' => 5, 'state' => 'resolved', 'remedy' => 'replacement', 'resolvedAt' => strtotime('2026-11-05 12:00')]));

        self::assertStringContainsString('title="erledigt"', $cells[0]);
        self::assertSame('Ersatz', $cells[5]);
        self::assertSame('', $cells[6], 'no lines');
        self::assertSame('05.11.2026', $cells[7]);
    }

    public function testARejectedComplaintSaysSoUnderRemedy(): void
    {
        $listener = $this->listener('consho_complaint');
        $listener->flatInModule('tl_consho_complaint');

        $cells = $listener->label($this->row(['state' => 'rejected', 'remedy' => '', 'resolvedAt' => strtotime('2026-11-06 12:00')]));

        self::assertSame('Ablehnung', $cells[5]);
    }

    public function testColumnsUnderTheOrder(): void
    {
        $listener = $this->listener('consho_order');
        $listener->flatInModule('tl_consho_complaint');

        $cells = $listener->label($this->row(['note' => 'Lid <cracked> '.str_repeat('x', 80)]));

        self::assertCount(6, $cells);
        self::assertStringEndsWith('</span>offen', $cells[0], 'under the order the state is also in words');
        self::assertSame('WEB-00077-R1', $cells[1]);
        self::assertSame('03.11.2026', $cells[2]);
        self::assertStringStartsWith('Lid &lt;cracked&gt; xxx', $cells[3], 'not stripped as a tag');
        self::assertStringEndsWith('…', $cells[3]);
        self::assertSame('1 × Herb vinegar, 2 × Herb salt', $cells[4]);
        self::assertSame('', $cells[5]);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function row(array $values): array
    {
        return $values + ['id' => 4, 'pid' => 7, 'number' => 'WEB-00077-R1', 'state' => 'open', 'remedy' => '', 'receivedAt' => strtotime('2026-11-03 12:00'), 'resolvedAt' => null, 'note' => ''];
    }

    private function listener(string $module): ComplaintListListener
    {
        $stack = new RequestStack();
        $stack->push(new Request(['do' => $module]));

        return new ComplaintListListener($this->connection, $stack, new MoneyFormatter());
    }
}

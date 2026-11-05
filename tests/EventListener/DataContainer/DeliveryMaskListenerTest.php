<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DeliveryMaskListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReport;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * A delivery in one mask: "Lieferung vollständig" and "Kunden verständigen".
 */
final class DeliveryMaskListenerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<int> */
    private array $reportedIds = [];

    private bool $granted = true;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '60']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Wanderstock', 'sku' => 'WS-1', 'quantity' => '2']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'productName' => 'Rucksack', 'quantity' => '1']);

        // A new delivery, prefilled with everything open.
        $this->connection->insert('tl_consho_delivery', ['id' => 3, 'pid' => 7]);
        $this->contents()->replace(3, ['1' => '2', '2' => '1'], 100);

        require __DIR__.'/../../../contao/languages/de/tl_consho_delivery.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testEineNeueLieferungIstVollstaendigAngehakt(): void
    {
        $html = $this->listener($this->edit())->contentsField($this->dc());

        self::assertMatchesRegularExpression('#name="consho_complete" id="ctrl_consho_complete" class="tl_checkbox" value="1" checked>#', $html);
        self::assertStringContainsString('<table class="tl_show with-padding" id="ctrl_consho_quantity" hidden>', $html);
        self::assertStringContainsString('Wanderstock (WS-1)', $html);
        self::assertStringContainsString('name="consho_quantity[1]" value="2"', $html);

        $notify = $this->listener($this->edit())->notifyField($this->dc());
        self::assertStringContainsString('Als versandt melden und Kunden verständigen', $notify);
        self::assertStringNotContainsString('checked', $notify, 'nicht vorausgewählt');
    }

    public function testNichtsOffenKeinMelden(): void
    {
        // Everything already sits in another draft.
        $this->connection->insert('tl_consho_delivery', ['id' => 4, 'pid' => 7]);
        $this->contents()->replace(3, [], 100);
        $this->contents()->replace(4, ['1' => '2', '2' => '1'], 100);

        $html = $this->listener($this->edit())->contentsField($this->dc());
        self::assertStringContainsString('nichts mehr zu versenden', $html);
        self::assertStringContainsString('Wanderstock (WS-1): bestellt 2, in einer anderen, noch nicht gemeldeten Lieferung 2', $html);
        self::assertSame('', $this->listener($this->edit())->notifyField($this->dc()));
    }

    /**
     * A good withdrawn before shipping: the mask says so, not "all shipped".
     */
    public function testWiderrufenStattVersandt(): void
    {
        $this->connection->insert('tl_consho_withdrawal',['id' => 1, 'pid' => 7, 'number' => 'WEB-7-W1', 'state' => 'accepted', 'decidedAt' => 100]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '0', 'acceptedQuantity' => '2']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 2, 'quantity' => '0', 'acceptedQuantity' => '1']);
        $this->contents()->replace(3, [], 100);

        $html = $this->listener($this->edit())->contentsField($this->dc());

        self::assertStringContainsString('Wanderstock (WS-1): bestellt 2, widerrufen 2', $html);
        self::assertStringContainsString('Rucksack: bestellt 1, widerrufen 1', $html);
        self::assertStringNotContainsString('versandt', $html);
    }

    public function testEineTeillieferungZeigtDieMengen(): void
    {
        $this->contents()->replace(3, ['1' => '1'], 100);

        $html = $this->listener($this->edit())->contentsField($this->dc());

        self::assertStringNotContainsString(' checked>', $html);
        self::assertStringContainsString('id="ctrl_consho_quantity">', $html, 'sichtbar');
        self::assertStringContainsString('name="consho_quantity[2]" value="0"', $html);
    }

    /**
     * Saving failed on another field: the mask is drawn again in the same
     * request and must show what was entered, not the stored state.
     */
    public function testNachFehlgeschlagenemSpeichernBleibtDieEingabe(): void
    {
        $request = $this->post(['consho_quantity' => ['1' => '1,5', '2' => '0'], 'consho_notify' => '1']);

        $html = $this->listener($request)->contentsField($this->dc());
        self::assertStringNotContainsString(' checked>', $html, 'Haken war aus');
        self::assertStringContainsString('id="ctrl_consho_quantity">', $html, 'sichtbar');
        self::assertStringContainsString('name="consho_quantity[1]" value="1,5"', $html);
        self::assertStringContainsString('name="consho_quantity[2]" value="0"', $html);

        self::assertStringContainsString(' checked>', $this->listener($request)->notifyField($this->dc()));
    }

    public function testSpeichernMitMengenErgibtDieTeillieferung(): void
    {
        $this->listener($this->post(['consho_quantity' => ['1' => '1', '2' => '0']]))->onSubmit($this->dc());

        self::assertSame([1 => '1'], $this->normalized($this->contents()->current(3)));
        self::assertSame([], $this->reportedIds, 'ohne Haken bleibt sie Entwurf');
    }

    public function testVollstaendigNimmtAllesOffeneWiederAuf(): void
    {
        $this->contents()->replace(3, ['1' => '1'], 100);

        $this->listener($this->post(['consho_complete' => '1', 'consho_quantity' => ['1' => '1', '2' => '0']]))->onSubmit($this->dc());

        self::assertSame([1 => '2', 2 => '1'], $this->normalized($this->contents()->current(3)));
    }

    public function testMehrAlsOffenWirdNichtUebernommen(): void
    {
        $this->listener($this->post(['consho_quantity' => ['1' => '3', '2' => '1'], 'consho_notify' => '1']))->onSubmit($this->dc());

        self::assertSame([1 => '2', 2 => '1'], $this->normalized($this->contents()->current(3)), 'unverändert');
        self::assertSame('addError', $this->messages[0][0]);
        self::assertSame([], $this->reportedIds, 'und nicht gemeldet');
    }

    public function testKundenVerstaendigenMeldetGleich(): void
    {
        $this->listener($this->post(['consho_complete' => '1', 'consho_notify' => '1']))->onSubmit($this->dc());

        self::assertSame([3], $this->reportedIds);
        self::assertSame(['addConfirmation', 'Die Lieferung zur Bestellung WEB-7 ist gemeldet, der Kunde hat die Versandmail bekommen.'], $this->messages[0]);
    }

    public function testOhneRechtAnDerBestellungKeinMelden(): void
    {
        $this->granted = false;

        $this->listener($this->post(['consho_complete' => '1', 'consho_notify' => '1']))->onSubmit($this->dc());

        self::assertSame([], $this->reportedIds);
        self::assertSame('addError', $this->messages[0][0]);
    }

    /**
     * A reported delivery is locked: no fields, and a forged post changes
     * nothing.
     */
    public function testEineGemeldeteLieferungZeigtUndAendertNichts(): void
    {
        $this->connection->update('tl_consho_delivery', ['reportedAt' => 200], ['id' => 3]);
        $GLOBALS['TL_DCA']['tl_consho_delivery']['palettes']['default'] = '{delivery_legend},shippedAt,carrier,trackingNumber;{contents_legend},contents;{report_legend},notify';

        $listener = $this->listener($this->post(['consho_quantity' => ['1' => '1'], 'consho_notify' => '1']));
        $listener->onLoad($this->dc());
        $listener->onSubmit($this->dc());

        self::assertSame('{delivery_legend},shippedAt,carrier,trackingNumber', $GLOBALS['TL_DCA']['tl_consho_delivery']['palettes']['default']);
        self::assertSame('', $listener->contentsField($this->dc()));
        self::assertSame([1 => '2', 2 => '1'], $this->normalized($this->contents()->current(3)));
        self::assertSame([], $this->reportedIds);

        unset($GLOBALS['TL_DCA']);
    }

    public function testOhneDieMaskeKeinEingriff(): void
    {
        // "Mehrere bearbeiten" or any other submit without the field.
        $request = Request::create('/contao?do=consho_order&table=tl_consho_delivery&act=edit&id=3', 'POST', ['consho_quantity' => ['1' => '1']]);

        $this->listener($request)->onSubmit($this->dc());

        self::assertSame([1 => '2', 2 => '1'], $this->normalized($this->contents()->current(3)));
    }

    private function edit(): Request
    {
        return Request::create('/contao?do=consho_order&table=tl_consho_delivery&act=edit&id=3');
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(array $fields): Request
    {
        return Request::create('/contao?do=consho_order&table=tl_consho_delivery&act=edit&id=3', 'POST', ['consho_contents' => '1'] + $fields);
    }

    private function dc(): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 3 : null);

        return $dc;
    }

    private function contents(): DeliveryContents
    {
        return new DeliveryContents($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
    }

    /**
     * @param array<int, string> $items
     *
     * @return array<int, string>
     */
    private function normalized(array $items): array
    {
        ksort($items);

        return array_map(static fn (string $q): string => rtrim(rtrim($q, '0'), '.'), $items);
    }

    private function listener(Request $request): DeliveryMaskListener
    {
        $requests = new RequestStack();
        $requests->push($request);

        $reportedIds = &$this->reportedIds;
        $reported = $this->createMock(DeliveryReported::class);
        $reported->method('report')->willReturnCallback(static function (int $id) use (&$reportedIds): DeliveryReport {
            $reportedIds[] = $id;

            return DeliveryReport::reported(true);
        });

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(fn (): bool => $this->granted);

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            $messages[] = [$name, (string) $args[0]];

            return '';
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($message);

        return new DeliveryMaskListener($this->connection, $this->contents(), $reported, $security, $requests, $framework);
    }
}

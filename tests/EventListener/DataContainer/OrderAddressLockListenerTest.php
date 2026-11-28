<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderAddressLockListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Connect-2, E2: customer and addresses can be corrected until a document
 * carries them; the period for the remainder until the invoice or the full
 * delivery; nothing but the status in a status that holds the order.
 */
final class OrderAddressLockListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_order_item', 'tl_consho_payment', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Order 5 has one physical good still to ship; the real balance reader decides what is open.
        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderNumber' => 'WEB-5', 'orderStatus' => 0, 'grandTotal' => '10.00']);
        $this->connection->insert('tl_consho_order', ['id' => 6, 'orderNumber' => 'WEB-6', 'orderStatus' => 0, 'grandTotal' => '10.00']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 5, 'lineType' => 'product', 'kind' => 'physical', 'quantity' => '1.0000']);

        $fields = ['orderStatus' => ['inputType' => 'select', 'eval' => []]];

        foreach ([...OrderAddressLockListener::CUSTOMER_FIELDS, ...OrderAddressLockListener::SHIPPING_FIELDS] as $field) {
            $fields[$field] = ['inputType' => str_ends_with($field, 'Country') ? 'select' : 'text', 'eval' => []];
        }

        $fields['remainderDeliveryDays'] = ['inputType' => 'text', 'eval' => []];

        $GLOBALS['TL_DCA']['tl_consho_order'] = ['config' => [], 'fields' => $fields];
        $GLOBALS['TL_LANG']['tl_consho_order'] = [
            'editNotice' => 'Bearbeitbar: %s. Rest: Kauf.',
            'editNoticeSingle' => 'Bearbeitbar ist nur %s. Rest: Kauf.',
            'editNoticeAnd' => ' und ',
            'editNoticeParts' => ['status' => 'Status', 'remainder' => 'Restlieferung', 'customer' => 'Kunde', 'shipping' => 'Lieferadresse'],
            'editNoticeInvoice' => 'Rechnung sperrt Kunde.',
            'editNoticeDelivery' => 'Lieferung sperrt Lieferadresse.',
            'editNoticeNoParcel' => 'Kein Paket.',
            'editNoticeRemainderInvoiced' => 'Frist: Rechnung.',
            'editNoticeRemainderComplete' => 'Frist: geliefert.',
            'editNoticeHeld' => 'Status „%s“ sperrt.',
        ];
        $this->messages = [];
    }

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $messages = [];

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testOhneBelegIstAllesKorrigierbar(): void
    {
        $this->listener()($this->dc(5));

        self::assertSame([], $this->readonly());
    }

    public function testEineRechnungFriertKundeUndRechnungsadresseEin(): void
    {
        $this->connection->insert('tl_consho_document', ['pid' => 5, 'type' => 'invoice', 'documentNumber' => 'R-1']);

        $this->listener()($this->dc(5));

        self::assertSame([...OrderAddressLockListener::CUSTOMER_FIELDS, 'remainderDeliveryDays'], $this->readonly(), 'die Frist wirkt nur auf die Rechnung');
    }

    public function testVollstaendigGeliefertSperrtDieFristFuerDieRestlieferung(): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 5, 'reportedAt' => 1700000000, 'complaint' => 0]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1.0000']);

        $this->listener()($this->dc(5));

        self::assertSame([...OrderAddressLockListener::SHIPPING_FIELDS, 'remainderDeliveryDays'], $this->readonly());
        self::assertSame([['addInfo', 'Bearbeitbar: Status und Kunde. Rest: Kauf. Lieferung sperrt Lieferadresse. Frist: geliefert.']], $this->messages);
    }

    public function testEineTeillieferungLaesstDieFristOffen(): void
    {
        $this->connection->update('tl_consho_order_item', ['quantity' => '2.0000'], ['id' => 1]);
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 5, 'reportedAt' => 1700000000, 'complaint' => 0]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1.0000']);

        $this->listener()($this->dc(5));

        self::assertSame(OrderAddressLockListener::SHIPPING_FIELDS, $this->readonly());
    }

    /**
     * Digital goods waiting for their release are still open in the balance,
     * but go into no parcel: the delivery address is locked, the remainder
     * period stays.
     */
    public function testDigitaleWareVorDerFreischaltungSperrtDieLieferadresse(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'digital'], ['id' => 1]);

        $this->listener()($this->dc(5));

        self::assertSame(OrderAddressLockListener::SHIPPING_FIELDS, $this->readonly());
        self::assertSame([['addInfo', 'Bearbeitbar: Status, Restlieferung und Kunde. Rest: Kauf. Kein Paket.']], $this->messages);
    }

    public function testEinOffenesPhysischesGutNebenDigitalemLaesstDieLieferadresseOffen(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 5, 'lineType' => 'product', 'kind' => 'digital', 'quantity' => '1.0000']);

        $this->listener()($this->dc(5));

        self::assertSame([], $this->readonly());
    }

    /**
     * The physical good was withdrawn before it left; only the digital one is
     * still open. A physical line on the order is not enough — it has to be
     * one still to ship.
     */
    public function testEinWiderrufenesPhysischesGutZaehltNichtMehr(): void
    {
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 5, 'lineType' => 'product', 'kind' => 'digital', 'quantity' => '1.0000']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 5, 'number' => 'W-1', 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'acceptedQuantity' => '1.0000']);

        $this->listener()($this->dc(5));

        self::assertSame(OrderAddressLockListener::SHIPPING_FIELDS, $this->readonly());
    }

    public function testEinePhysischeZeileEinerAnderenBestellungZaehltNicht(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'digital'], ['id' => 1]);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 6, 'lineType' => 'product', 'kind' => 'physical', 'quantity' => '1.0000']);

        $this->listener()($this->dc(5));

        self::assertSame(OrderAddressLockListener::SHIPPING_FIELDS, $this->readonly());
    }

    public function testEineStornierteBestellungHatNichtsMehrZuLiefern(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 1700000000], ['id' => 5]);

        $this->listener()($this->dc(5));

        self::assertSame([...OrderAddressLockListener::SHIPPING_FIELDS, 'remainderDeliveryDays'], $this->readonly());
        self::assertSame([['addInfo', 'Bearbeitbar: Status und Kunde. Rest: Kauf. Kein Paket. Frist: geliefert.']], $this->messages);
    }

    /**
     * @return iterable<string, array{0: array<string, int>}>
     */
    public static function sperrendeStatus(): iterable
    {
        yield 'schließt ab' => [['isCompleted' => 1]];
        yield 'sperrt Änderungen' => [['locksChanges' => 1]];
    }

    /**
     * @param array<string, int> $flags
     *
     * @dataProvider sperrendeStatus
     */
    public function testEinSperrenderStatusLaesstNurDenStatus(array $flags): void
    {
        $this->connection->insert('tl_consho_order_status', ['id' => 3, 'title' => 'Ab<ge>schlossen', ...$flags]);
        $this->connection->update('tl_consho_order', ['orderStatus' => 3], ['id' => 5]);

        $this->listener()($this->dc(5));

        $all = array_keys($GLOBALS['TL_DCA']['tl_consho_order']['fields']);
        self::assertSame(array_values(array_diff($all, ['orderStatus'])), $this->readonly());
        self::assertSame([['addInfo', 'Bearbeitbar ist nur Status. Rest: Kauf. Status „Ab&lt;ge&gt;schlossen“ sperrt.']], $this->messages);

        self::assertSame(['orderStatus' => 1], $this->listener()->refuseFrozen(['orderStatus' => 1], $this->dc(5)), 'der Status bleibt änderbar');

        $this->expectException(\RuntimeException::class);
        $this->listener()->refuseFrozen(['remainderDeliveryDays' => 3], $this->dc(5));
    }

    public function testEinGewoehnlicherStatusSperrtNichts(): void
    {
        $this->connection->insert('tl_consho_order_status', ['id' => 2, 'title' => 'Bezahlt', 'isPaid' => 1, 'isShipped' => 1]);
        $this->connection->update('tl_consho_order', ['orderStatus' => 2], ['id' => 5]);

        $this->listener()($this->dc(5));

        self::assertSame([], $this->readonly());
    }

    public function testEineLieferungFriertDieLieferadresseEin(): void
    {
        $this->connection->insert('tl_consho_delivery', ['pid' => 5]);

        $this->listener()($this->dc(5));

        self::assertSame(OrderAddressLockListener::SHIPPING_FIELDS, $this->readonly());
    }

    public function testEinLieferscheinFriertDieLieferadresseEin(): void
    {
        $this->connection->insert('tl_consho_document', ['pid' => 5, 'type' => 'deliveryNote', 'documentNumber' => 'L-1']);

        $this->listener()($this->dc(5));

        self::assertSame(OrderAddressLockListener::SHIPPING_FIELDS, $this->readonly());
    }

    public function testBelegeEinerAnderenBestellungZaehlenNicht(): void
    {
        $this->connection->insert('tl_consho_document', ['pid' => 6, 'type' => 'invoice', 'documentNumber' => 'R-1']);
        $this->connection->insert('tl_consho_delivery', ['pid' => 6]);

        $this->listener()($this->dc(5));

        self::assertSame([], $this->readonly());
    }

    public function testEinGesperrtesAuswahlfeldWirdAbgeschaltetNichtSchreibgeschuetzt(): void
    {
        // Browsers ignore readonly on a select; only disabled keeps it from changing.
        $this->connection->insert('tl_consho_document', ['pid' => 5, 'type' => 'invoice', 'documentNumber' => 'R-1']);

        $this->listener()($this->dc(5));

        $fields = $GLOBALS['TL_DCA']['tl_consho_order']['fields'];
        self::assertTrue($fields['billingCountry']['eval']['disabled'] ?? false);
        self::assertArrayNotHasKey('readonly', $fields['billingCountry']['eval']);
        self::assertTrue($fields['billingStreet']['eval']['readonly'] ?? false);
        self::assertArrayNotHasKey('disabled', $fields['billingStreet']['eval']);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function hinweise(): iterable
    {
        yield 'ohne Beleg' => [[], 'Bearbeitbar: Status, Restlieferung, Kunde und Lieferadresse. Rest: Kauf.'];
        yield 'Rechnung' => [['invoice'], 'Bearbeitbar: Status und Lieferadresse. Rest: Kauf. Rechnung sperrt Kunde. Frist: Rechnung.'];
        yield 'Lieferung' => [['delivery'], 'Bearbeitbar: Status, Restlieferung und Kunde. Rest: Kauf. Lieferung sperrt Lieferadresse.'];
        yield 'Lieferschein' => [['deliveryNote'], 'Bearbeitbar: Status, Restlieferung und Kunde. Rest: Kauf. Lieferung sperrt Lieferadresse.'];
        yield 'beides' => [['invoice', 'delivery'], 'Bearbeitbar ist nur Status. Rest: Kauf. Rechnung sperrt Kunde. Lieferung sperrt Lieferadresse. Frist: Rechnung.'];
    }

    /**
     * The mask says on every open what can be edited and, where something is
     * locked, why — not only once something is frozen.
     *
     * @param list<string> $documents
     *
     * @dataProvider hinweise
     */
    public function testDerHinweisNenntWasBearbeitbarIstUndWarum(array $documents, string $expected): void
    {
        foreach ($documents as $type) {
            'delivery' === $type
                ? $this->connection->insert('tl_consho_delivery', ['pid' => 5])
                : $this->connection->insert('tl_consho_document', ['pid' => 5, 'type' => $type, 'documentNumber' => $type]);
        }

        $this->listener()($this->dc(5));

        self::assertSame([['addInfo', $expected]], $this->messages);
    }

    public function testKeinHinweisAusserhalbDerBearbeitung(): void
    {
        $this->listener('editAll')($this->dc(5));
        $this->listener()($this->dc(0));

        self::assertSame([], $this->messages);
    }

    public function testEineGutschriftAlleinFriertNichts(): void
    {
        // Only an invoice carries customer and billing address first; there is no correction without one,
        // but the rule hangs on the document type, not on "any document".
        $this->connection->insert('tl_consho_document', ['pid' => 5, 'type' => 'creditNote', 'documentNumber' => 'K-1']);

        $this->listener()($this->dc(5));

        self::assertSame([], $this->readonly());
    }

    /**
     * "Edit multiple" and "Override" build their own widgets; the read-only
     * marker does not reach them. Hence saving itself rejects a frozen field —
     * DC_Table passes on only changed fields, so saving unchanged ones along
     * does no harm.
     */
    public function testDasSpeichernLehntEingefroreneFelderAb(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_order']['addressesFrozen'] = 'Eingefroren';
        $this->connection->insert('tl_consho_document', ['pid' => 5, 'type' => 'invoice', 'documentNumber' => 'R-1']);

        $values = ['orderStatus' => 3, 'shippingStreet' => 'Neu 1'];
        self::assertSame($values, $this->listener()->refuseFrozen($values, $this->dc(5)), 'Status und noch offene Lieferadresse');
        self::assertSame(['billingCity' => 'Graz'], $this->listener()->refuseFrozen(['billingCity' => 'Graz'], $this->dc(6)), 'eine andere Bestellung');

        $this->expectExceptionObject(new \RuntimeException('Eingefroren (billingStreet, email)'));

        $this->listener()->refuseFrozen(['orderStatus' => 3, 'billingStreet' => 'Neu 2', 'email' => 'neu@example.org'], $this->dc(5));
    }

    public function testDieCallbacksSindAngemeldet(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(OrderAddressLockListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(\Contao\CoreBundle\DependencyInjection\Attribute\AsCallback::class) as $attribute) {
                $targets[] = $attribute->getArguments()['target'].' '.$method->getName();
            }
        }

        self::assertSame(['config.onload __invoke', 'config.onbeforesubmit refuseFrozen'], $targets);
    }

    /**
     * The lists cover exactly the generated customer and address fields of the
     * real DCA — a newly added address field shows up here instead of silently
     * staying editable.
     */
    public function testDieListenDeckenDieAdressfelderDerDca(): void
    {
        unset($GLOBALS['TL_DCA']);
        $GLOBALS['TL_LANG']['tl_consho_order'] = [];
        include __DIR__.'/../../../contao/dca/tl_consho_order.php';

        $generated = [];

        foreach ($GLOBALS['TL_DCA']['tl_consho_order']['fields'] as $name => $field) {
            // An operator's setting read when the invoice is issued, not a customer detail (E-6b).
            if ('remainderDeliveryDays' === $name) {
                continue;
            }

            // The generated fields carry their label by reference — null while no language file is loaded.
            if (\array_key_exists('label', $field) && \in_array($field['inputType'] ?? null, ['text', 'select'], true) && !isset($field['eval']['readonly']) && !isset($field['eval']['disabled'])) {
                $generated[] = $name;
            }
        }

        $listed = [...OrderAddressLockListener::CUSTOMER_FIELDS, ...OrderAddressLockListener::SHIPPING_FIELDS];
        sort($generated);
        sort($listed);

        self::assertSame($generated, $listed);
    }

    /**
     * @return list<string>
     */
    private function readonly(): array
    {
        $names = [];

        foreach ($GLOBALS['TL_DCA']['tl_consho_order']['fields'] as $name => $field) {
            if (($field['eval']['readonly'] ?? false) || ($field['eval']['disabled'] ?? false)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function listener(string $act = 'edit'): OrderAddressLockListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => $act]));

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): void {
            $messages[] = [$name, (string) $args[0]];
        });

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($message);

        // The real reader, not a stub: what counts as "left to ship" is its decision.
        $balances = new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());

        return new OrderAddressLockListener($this->connection, $requests, $framework, $balances);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => $id,
            'table' => 'tl_consho_order',
            default => null,
        });

        return $dc;
    }
}

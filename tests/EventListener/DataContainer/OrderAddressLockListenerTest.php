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
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Connect-2, E2: customer and addresses can be corrected until a document
 * carries them.
 */
final class OrderAddressLockListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_delivery');

        $fields = ['orderStatus' => ['inputType' => 'select', 'eval' => []]];

        foreach ([...OrderAddressLockListener::CUSTOMER_FIELDS, ...OrderAddressLockListener::SHIPPING_FIELDS] as $field) {
            $fields[$field] = ['inputType' => 'text', 'eval' => []];
        }

        $GLOBALS['TL_DCA']['tl_consho_order'] = ['config' => [], 'fields' => $fields];
    }

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

        self::assertSame(OrderAddressLockListener::CUSTOMER_FIELDS, $this->readonly());
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
     * marker does not reach them (CW-6). Hence saving itself rejects a frozen field —
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
            if (\array_key_exists('label', $field) && 'text' === ($field['inputType'] ?? null) && !isset($field['eval']['readonly'])) {
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
            if ($field['eval']['readonly'] ?? false) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function listener(): OrderAddressLockListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => 'edit']));

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->createStub(Adapter::class));

        return new OrderAddressLockListener($this->connection, $requests, $framework);
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

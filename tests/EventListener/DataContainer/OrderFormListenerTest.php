<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderFormListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The order mask shows the purchase readably: the member by name, the
 * country by name, and no empty status to pick once one is set.
 */
final class OrderFormListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, email TEXT)');

        $GLOBALS['TL_LANG']['tl_consho_order'] = ['memberGuest' => 'Gast', 'memberMissing' => 'nicht mehr vorhanden (ID %d)'];
        $GLOBALS['TL_DCA']['tl_consho_order']['fields']['orderStatus'] = ['inputType' => 'select', 'eval' => ['includeBlankOption' => true]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testDasMitgliedMitNamen(): void
    {
        $this->connection->insert('tl_member', ['id' => 3, 'firstname' => 'Erika', 'lastname' => 'Muster', 'email' => 'erika@example.org']);

        self::assertSame('Erika Muster (erika@example.org)', $this->listener()->member(3));
        self::assertSame('Erika Muster (erika@example.org)', $this->listener()->member('3'), 'aus der DB als Zeichenkette');
    }

    public function testGastUndGeloeschtesMitglied(): void
    {
        self::assertSame('Gast', $this->listener()->member(0));
        self::assertSame('Gast', $this->listener()->member(null));
        self::assertSame('nicht mehr vorhanden (ID 9)', $this->listener()->member(9));
    }

    public function testDieLaenderMitNamenUndGrossbuchstaben(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';
        $options = $this->listener()->countries();

        self::assertSame('Österreich', $options['AT'] ?? null);
        self::assertSame('Schweiz', $options['CH'] ?? null, 'auch außerhalb der Liefergebiete — eine Bestellung behält ihr Land');
        self::assertArrayNotHasKey('at', $options);
    }

    public function testDieLaenderfelderSindAuswahlfelder(): void
    {
        unset($GLOBALS['TL_DCA']);
        $GLOBALS['TL_LANG']['tl_consho_order'] = [];
        include __DIR__.'/../../../contao/dca/tl_consho_order.php';

        foreach (['billingCountry', 'shippingCountry'] as $field) {
            self::assertSame('select', $GLOBALS['TL_DCA']['tl_consho_order']['fields'][$field]['inputType'], $field);
            self::assertTrue($GLOBALS['TL_DCA']['tl_consho_order']['fields'][$field]['eval']['includeBlankOption'], $field);
        }

        self::assertSame('text', $GLOBALS['TL_DCA']['tl_consho_order']['fields']['billingCity']['inputType']);
    }

    public function testKeinLeererStatusSobaldEinerGesetztIst(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderStatus' => 2]);

        $this->listener()->statusBlank($this->dc(5));

        self::assertFalse($GLOBALS['TL_DCA']['tl_consho_order']['fields']['orderStatus']['eval']['includeBlankOption']);
    }

    /**
     * Without a status the blank stays: removing it would preselect the first
     * status, and saving would set it without anyone choosing.
     */
    public function testOhneStatusBleibtDerLeereEintrag(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderStatus' => 0]);

        $this->listener()->statusBlank($this->dc(5));
        $this->listener('editAll')->statusBlank($this->dc(6));

        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_order']['fields']['orderStatus']['eval']['includeBlankOption']);
    }

    public function testNurInDerEinzelbearbeitung(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderStatus' => 2]);

        $this->listener('editAll')->statusBlank($this->dc(5));
        $this->listener('')->statusBlank($this->dc(5));

        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_order']['fields']['orderStatus']['eval']['includeBlankOption']);
    }

    public function testDieCallbacksSindAngemeldet(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(OrderFormListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                $targets[] = $attribute->getArguments()['target'].' '.$method->getName();
            }
        }

        self::assertSame([
            'fields.member.load member',
            'fields.billingCountry.options countries',
            'fields.shippingCountry.options countries',
            'config.onload statusBlank',
        ], $targets);
    }

    private function listener(string $act = 'edit'): OrderFormListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => $act]));

        return new OrderFormListener($this->connection, $requests);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderAftercareButtonListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * F13 — Michael: „das Icon für Widerrufe ist immer grün und anwählbar, es wäre
 * besser wenn es nur bei einem widerruf so ist"; „was für das Widerruf icon
 * gilt, gilt auch für die Reklamation".
 */
final class OrderAftercareButtonListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_withdrawal');
        DcaSchema::create($this->connection, 'tl_consho_complaint');
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 7]);
        $this->connection->insert('tl_consho_complaint', ['pid' => 8]);

        $GLOBALS['TL_LANG']['tl_consho_order']['complaintsNone'] = 'keine';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testWiderrufNurBeiEinemWiderrufAnwaehlbar(): void
    {
        $listener = new OrderAftercareButtonListener($this->connection);

        $with = $this->operation('withdrawals', 'undo.svg', 7);
        $listener->withdrawals($with);
        self::assertSame('undo.svg', $with['icon']);
        self::assertSame('table=tl_consho_withdrawal', $with['href']);

        $without = $this->operation('withdrawals', 'undo.svg', 8);
        $listener->withdrawals($without);
        self::assertSame('undo--disabled.svg', $without['icon']);
        self::assertFalse(isset($without['href']), 'gesperrt, nicht nur grau');
    }

    public function testReklamationOhneEintragGrauAberErfassbar(): void
    {
        $listener = new OrderAftercareButtonListener($this->connection);

        $with = $this->operation('complaints', 'important.svg', 8);
        $listener->complaints($with);
        self::assertSame('important.svg', $with['icon']);

        $without = $this->operation('complaints', 'important.svg', 7);
        $listener->complaints($without);
        self::assertSame(OrderAftercareButtonListener::COMPLAINT_ICON_NONE, $without['icon']);
        self::assertSame('table=tl_consho_complaint', $without['href'], 'die erste Reklamation muss sich anlegen lassen');
        self::assertSame('keine', $without['title']);
        self::assertFileExists(__DIR__.'/../../../public/icons/important--none.svg');
    }

    public function testEineAbfrageJeTabelleNichtJeZeile(): void
    {
        $listener = new OrderAftercareButtonListener($this->connection);
        $listener->withdrawals($this->operation('withdrawals', 'undo.svg', 7));

        $this->connection->insert('tl_consho_withdrawal', ['pid' => 9]);
        $late = $this->operation('withdrawals', 'undo.svg', 9);
        $listener->withdrawals($late);

        self::assertSame('undo--disabled.svg', $late['icon'], 'der erste Aufruf hat die Liste gelesen');
    }

    private function operation(string $name, string $icon, int $orderId): DataContainerOperation
    {
        $table = 'withdrawals' === $name ? 'tl_consho_withdrawal' : 'tl_consho_complaint';

        return new DataContainerOperation($name, ['href' => 'table='.$table, 'icon' => $icon, 'label' => [$name, '%s']], ['id' => $orderId], $this->createStub(DataContainer::class));
    }
}

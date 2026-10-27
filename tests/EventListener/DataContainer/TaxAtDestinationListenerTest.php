<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TaxAtDestinationListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block R (O3, R1): the switch belongs to the company — same VAT ID, same
 * value, and the form says where else it was set.
 */
class TaxAtDestinationListenerTest extends TestCase
{
    private Connection $connection;

    /** @var list<string> */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationShared'] = 'auch %s: %s';
        $GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationOn'] = 'an';
        $GLOBALS['TL_LANG']['tl_consho_shop']['taxAtDestinationOff'] = 'aus';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']['tl_consho_shop']);
    }

    public function testGleicheUidUebernimmtDenWertUndWirdGenannt(): void
    {
        $this->shop(1, 'Wien', 'ATU12345678', true);
        $this->shop(2, 'Berlin', 'atu 1234 5678', false);
        $this->shop(3, 'Fremd', 'ATU99999999', false);
        $this->shop(4, 'Ohne UID', '', false);

        self::assertSame(['Berlin'], $this->listener()->spread(1, 1_700_000_000));
        self::assertSame([1 => 1, 2 => 1, 3 => 0, 4 => 0], $this->values());
        self::assertSame(['auch an: Berlin'], $this->messages);
    }

    public function testAusschaltenGiltEbensoFuerAlle(): void
    {
        $this->shop(1, 'Wien', 'ATU12345678', false);
        $this->shop(2, 'Berlin', 'ATU12345678', true);

        $this->listener()->spread(1, 1_700_000_000);

        self::assertSame([1 => 0, 2 => 0], $this->values());
        self::assertSame(['auch aus: Berlin'], $this->messages);
    }

    public function testOhneEigeneUidStehtDerShopFuerSich(): void
    {
        $this->shop(1, 'Wien', '', true);
        $this->shop(2, 'Berlin', '', false);

        self::assertSame([], $this->listener()->spread(1, 1_700_000_000));
        self::assertSame([1 => 1, 2 => 0], $this->values());
        self::assertSame([], $this->messages);
    }

    public function testSchonGleichOhneMeldung(): void
    {
        $this->shop(1, 'Wien', 'ATU12345678', true);
        $this->shop(2, 'Berlin', 'ATU12345678', true);

        self::assertSame([], $this->listener()->spread(1, 1_700_000_000));
        self::assertSame([], $this->messages);
    }

    private function shop(int $id, string $title, string $vatId, bool $taxAtDestination): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'tstamp' => 1, 'title' => $title, 'vatId' => $vatId, 'taxAtDestination' => $taxAtDestination ? 1 : 0]);
    }

    /**
     * @return array<int, int>
     */
    private function values(): array
    {
        return array_map('intval', $this->connection->fetchAllKeyValue('SELECT id, taxAtDestination FROM tl_consho_shop ORDER BY id'));
    }

    private function listener(): TaxAtDestinationListener
    {
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(function (string $method, array $arguments): null {
            if ('addConfirmation' === $method) {
                $this->messages[] = (string) $arguments[0];
            }

            return null;
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(Message::class)->willReturn($message);

        return new TaxAtDestinationListener($this->connection, $framework);
    }
}

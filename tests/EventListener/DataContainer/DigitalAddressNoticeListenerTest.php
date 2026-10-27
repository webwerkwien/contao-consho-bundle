<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DigitalAddressNoticeListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Nachlese E4: digital goods to an unconfirmed address — a notice on the
 * order, not a block.
 */
final class DigitalAddressNoticeListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_entitlement');
        DcaSchema::createCore($this->connection, 'tl_member');

        $this->connection->insert('tl_member', ['id' => 5, 'username' => 'kundin', 'consho_confirmedEmail' => 'alt@example.org']);
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'member' => 5, 'email' => 'Kundin@Example.org']);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 7]);
    }

    public function testUnbestaetigteAdresseMitBerechtigung(): void
    {
        self::assertSame('Kundin@Example.org', $this->listener()->unconfirmedAddress(7));
    }

    public function testUeberDieBestellseiteBestaetigt(): void
    {
        $this->connection->update('tl_consho_order', ['emailConfirmedAt' => 1000], ['id' => 7]);

        self::assertNull($this->listener()->unconfirmedAddress(7));
    }

    public function testDasKontoHatGenauDieseAdresseBestaetigt(): void
    {
        $this->connection->update('tl_member', ['consho_confirmedEmail' => 'kundin@example.org'], ['id' => 5]);

        self::assertNull($this->listener()->unconfirmedAddress(7), 'Groß-/Kleinschreibung zählt nicht');
    }

    public function testOhneDigitaleGueterKeinHinweis(): void
    {
        $this->connection->delete('tl_consho_entitlement', ['pid' => 7]);

        self::assertNull($this->listener()->unconfirmedAddress(7));
    }

    public function testGastOhneBestaetigung(): void
    {
        $this->connection->update('tl_consho_order', ['member' => 0], ['id' => 7]);

        self::assertSame('Kundin@Example.org', $this->listener()->unconfirmedAddress(7));
    }

    public function testDerHinweisNurBeimBearbeiten(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_order']['digitalAddressUnconfirmed'] = 'an %s';

        $messages = [];
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->willReturnCallback(static function (string $method, array $args) use (&$messages): void {
            $messages[] = [$method, $args[0]];
        });
        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $adapter : throw new \LogicException($class));

        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 7 : null);

        $this->listener($framework, new Request(['act' => 'edit']))($dc);
        $this->listener($framework, new Request())($dc);

        self::assertSame([['addInfo', 'an Kundin@Example.org']], $messages);

        unset($GLOBALS['TL_LANG']);
    }

    private function listener(ContaoFramework|null $framework = null, Request|null $request = null): DigitalAddressNoticeListener
    {
        $requests = new RequestStack();
        $requests->push($request ?? new Request(['act' => 'edit']));

        return new DigitalAddressNoticeListener($this->connection, $requests, $framework ?? $this->createStub(ContaoFramework::class));
    }
}

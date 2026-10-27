<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Customer\MemberOrders;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * D-4, K3 — "my orders": the account's orders in the shop, and guest
 * orders with the account address only after confirmation — Contao's
 * "personal data" changes the address without any check.
 */
final class MemberOrdersTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, email TEXT NOT NULL DEFAULT '', consho_confirmedEmail TEXT NOT NULL DEFAULT '', consho_confirmedEmailAt INTEGER NULL)");
        DcaSchema::create($this->connection, 'tl_consho_order');

        $this->connection->insert('tl_member', ['id' => 5, 'email' => 'Erika@Example.org']);
        $this->connection->insert('tl_member', ['id' => 6, 'email' => 'andere@example.org']);

        $this->order(1, 1, 5, 'erika@example.org', 100);
        $this->order(2, 1, 0, 'ERIKA@example.org', 300);   // guest, her address
        $this->order(3, 2, 5, 'erika@example.org', 200);   // another shop
        $this->order(4, 1, 6, 'erika@example.org', 400);   // someone else's account with her address
        $this->order(5, 1, 0, 'fremd@example.org', 500);   // someone else as a guest
    }

    public function testOhneBestaetigungNurDieKontobestellungen(): void
    {
        self::assertSame([1], array_column($this->orders()->list(1, 5), 'id'));
        self::assertFalse($this->orders()->guestOrdersConfirmed(5));
        self::assertTrue($this->orders()->owns(1, 5, 1));
        self::assertFalse($this->orders()->owns(1, 5, 2), 'Gastbestellung erst nach Bestätigung');
        self::assertFalse($this->orders()->owns(1, 5, 3), 'anderer Shop');
        self::assertFalse($this->orders()->owns(1, 5, 4), 'Bestellung eines anderen Kontos, auch mit ihrer Adresse');
        self::assertFalse($this->orders()->owns(1, 5, 5));
    }

    public function testNachBestaetigungAuchDieGastbestellungen(): void
    {
        $this->orders()->markConfirmed(5, 'ERIKA@example.org', 999);

        self::assertTrue($this->orders()->guestOrdersConfirmed(5));
        self::assertSame([2, 1], array_column($this->orders()->list(1, 5), 'id'), 'neueste zuerst; nie die eines anderen Kontos');
        self::assertTrue($this->orders()->list(1, 5)[0]['guest']);
        self::assertTrue($this->orders()->owns(1, 5, 2));
        self::assertFalse($this->orders()->owns(1, 5, 4));
        self::assertFalse($this->orders()->owns(1, 5, 5));
        self::assertSame(999, (int) $this->connection->fetchOne('SELECT consho_confirmedEmailAt FROM tl_member WHERE id = 5'));
    }

    public function testEineGeaenderteAdresseBrauchtEineNeueBestaetigung(): void
    {
        $this->orders()->markConfirmed(5, 'erika@example.org', 999);
        $this->connection->update('tl_member', ['email' => 'neu@example.org'], ['id' => 5]);

        self::assertFalse($this->orders()->guestOrdersConfirmed(5));
        self::assertSame([1], array_column($this->orders()->list(1, 5), 'id'));
        self::assertFalse($this->orders()->owns(1, 5, 2));
    }

    public function testDieBestaetigteAdresseMussDieDesKontosSein(): void
    {
        // An address was confirmed that the account no longer has.
        $this->orders()->markConfirmed(5, 'fremd@example.org', 999);

        self::assertFalse($this->orders()->guestOrdersConfirmed(5));
        self::assertFalse($this->orders()->owns(1, 5, 5));
    }

    public function testKeinKontoKeineBestellungen(): void
    {
        self::assertSame([], $this->orders()->list(1, 0));
        self::assertSame([], $this->orders()->list(1, 77));
        self::assertFalse($this->orders()->owns(1, 0, 5));
    }

    private function order(int $id, int $shop, int $member, string $email, int $createdAt): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => $shop, 'member' => $member, 'orderNumber' => 'WEB-'.$id, 'email' => $email, 'createdAt' => $createdAt]);
    }

    private function orders(): MemberOrders
    {
        return new MemberOrders($this->connection);
    }
}

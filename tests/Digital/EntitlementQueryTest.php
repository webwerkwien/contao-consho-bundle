<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Digital\Entitlement;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementQuery;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-5 (P40): die Andockstelle, über die Module fragen, ob eine Berechtigung
 * jetzt zählt — dieselbe Antwort wie die Bestellseite.
 */
final class EntitlementQueryTest extends TestCase
{
    private const NOW = 2_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item', 'tl_consho_entitlement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 5, 'orderNumber' => 'WEB-00005']);
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 5, 'quantity' => '1']);
        $this->connection->insert('tl_consho_order', ['id' => 6, 'orderNumber' => 'WEB-00006']);
        $this->connection->insert('tl_consho_order_item', ['id' => 11, 'pid' => 6, 'quantity' => '1']);

        // Kunde 7, Produkt 4: aktiv, ruhend, gesperrt, abgelaufen; dazu fremder Kunde und fremdes Produkt.
        $this->entitlement(1, 5, 10, 7, 4);
        $this->entitlement(2, 5, 10, 7, 4, ['suspendedAt' => 1_500]);
        $this->entitlement(3, 5, 10, 7, 4, ['revoked' => 1]);
        $this->entitlement(4, 5, 10, 7, 4, ['validUntil' => 1_900]);
        $this->entitlement(5, 5, 10, 8, 4);
        $this->entitlement(6, 5, 10, 7, 9);
        $this->entitlement(7, 6, 11, 7, 4, ['validFrom' => 500]);
    }

    public function testAktivFuerKundeUndProdukt(): void
    {
        self::assertSame([7, 1], $this->ids($this->query()->activeFor(7, 4, self::NOW)), 'älteste zuerst');
    }

    public function testOhneKontoNichts(): void
    {
        $this->entitlement(8, 5, 10, 0, 4);

        self::assertSame([], $this->query()->activeFor(0, 4, self::NOW), 'Review L4: nicht die Gäste aller');
    }

    public function testEinzelneBerechtigung(): void
    {
        $query = $this->query();

        self::assertTrue($query->isActive(1, self::NOW));
        self::assertFalse($query->isActive(2, self::NOW), 'ruht (P33)');
        self::assertFalse($query->isActive(3, self::NOW), 'gesperrt');
        self::assertFalse($query->isActive(4, self::NOW), 'abgelaufen');
        self::assertFalse($query->isActive(99, self::NOW));
        self::assertSame(2, $query->find(2)?->id);
    }

    public function testEineStornierteBestellungZaehltNicht(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 1_800], ['id' => 6]);

        self::assertFalse($this->query()->isActive(7, self::NOW));
        self::assertSame([1], $this->ids($this->query()->activeFor(7, 4, self::NOW)));
    }

    public function testNurEinAngenommenerVollerWiderrufBeendetDieZeile(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 6, 'state' => 'open']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 11, 'quantity' => '1']);

        self::assertTrue($this->query()->isActive(7, self::NOW), 'noch nicht entschieden');

        $this->connection->update('tl_consho_withdrawal', ['state' => 'accepted'], ['id' => 1]);

        self::assertFalse($this->query()->isActive(7, self::NOW));
    }

    private function query(): EntitlementQuery
    {
        return new EntitlementQuery($this->connection);
    }

    /**
     * @param list<Entitlement> $entitlements
     *
     * @return list<int>
     */
    private function ids(array $entitlements): array
    {
        return array_map(static fn (Entitlement $e): int => $e->id, $entitlements);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function entitlement(int $id, int $order, int $item, int $member, int $product, array $values = []): void
    {
        $this->connection->insert('tl_consho_entitlement', $values + ['id' => $id, 'pid' => $order, 'orderItem' => $item, 'member' => $member, 'product' => $product, 'quantity' => '1', 'deliveryType' => 'file', 'validFrom' => 1_000]);
    }
}

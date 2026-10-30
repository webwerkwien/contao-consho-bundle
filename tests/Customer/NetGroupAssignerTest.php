<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoConshoBundle\Customer\NetGroupAssigner;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * A verified VAT ID adds the net price groups of every shop with the switch
 * on — only adds, only for "valid", and lists only what it added.
 */
final class NetGroupAssignerTest extends TestCase
{
    private const NOW = 1_793_000_000;

    private Connection $connection;

    private InfoSpyLogger $logger;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_member');
        DcaSchema::createCore($this->connection, 'tl_member_group');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        foreach ([3, 7, 8, 9] as $id) {
            $this->connection->insert('tl_member_group', ['id' => $id, 'name' => 'Gruppe '.$id]);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'A', 'netPriceGroups' => serialize(['7', '8']), 'vatIdNetGroups' => 1]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'B', 'netPriceGroups' => serialize(['9']), 'vatIdNetGroups' => 0]);
        $this->logger = new InfoSpyLogger();
        $this->connection->insert('tl_member', ['id' => 5, 'username' => 'kunde', '"groups"' => serialize(['3']), 'consho_vatIdStatus' => 'valid']);
    }

    public function testFuegtDieGruppenDerShopsMitSchalterHinzu(): void
    {
        self::assertSame([7, 8], $this->assigner()->assign(5, self::NOW));

        self::assertSame(['groups' => ['3', '7', '8'], 'auto' => ['7', '8'], 'at' => self::NOW], $this->member());
        self::assertCount(1, $this->logger->messages);
        self::assertStringContainsString('Member ID 5', $this->logger->messages[0]);
    }

    public function testVereinigungUeberAlleShops(): void
    {
        $this->connection->update('tl_consho_shop', ['vatIdNetGroups' => 1], ['id' => 2]);

        self::assertSame([7, 8, 9], $this->assigner()->assign(5, self::NOW));
    }

    public function testSchonVorhandeneGruppeBleibtVonHand(): void
    {
        $this->connection->update('tl_member', ['"groups"' => serialize(['3', '7'])], ['id' => 5]);

        self::assertSame([8], $this->assigner()->assign(5, self::NOW));
        self::assertSame(['8'], $this->member()['auto']);
    }

    public function testNichtsZuTunNichtsGeschrieben(): void
    {
        $this->connection->update('tl_member', ['"groups"' => serialize(['3', '7', '8'])], ['id' => 5]);

        self::assertSame([], $this->assigner()->assign(5, self::NOW));
        self::assertNull($this->member()['at']);
        self::assertSame([], $this->logger->messages);
    }

    public function testOhneGruppenAmMitglied(): void
    {
        // NULL, as Contao leaves a member without groups — the write's guard must match it.
        $this->connection->update('tl_member', ['"groups"' => null], ['id' => 5]);

        self::assertSame([7, 8], $this->assigner()->assign(5, self::NOW));
        self::assertSame(['7', '8'], $this->member()['groups']);
    }

    public function testNurBeiGueltigerUid(): void
    {
        foreach (['invalid', 'unavailable', ''] as $status) {
            $this->connection->update('tl_member', ['consho_vatIdStatus' => $status], ['id' => 5]);

            self::assertSame([], $this->assigner()->assign(5, self::NOW), $status);
        }

        self::assertSame(['3'], $this->member()['groups']);
    }

    public function testOhneSchalterNichts(): void
    {
        $this->connection->update('tl_consho_shop', ['vatIdNetGroups' => 0], ['id' => 1]);

        self::assertSame([], $this->assigner()->assign(5, self::NOW));
    }

    public function testGeloeschteGruppeWirdUebergangen(): void
    {
        $this->connection->delete('tl_member_group', ['id' => 8]);

        self::assertSame([7], $this->assigner()->assign(5, self::NOW));
    }

    public function testVermerkWaechstUndNimmtNichtsWeg(): void
    {
        $this->connection->update('tl_member', ['consho_netGroupsAuto' => serialize(['7']), '"groups"' => serialize(['3', '7'])], ['id' => 5]);
        $this->connection->update('tl_consho_shop', ['vatIdNetGroups' => 1], ['id' => 2]);

        $this->assigner()->assign(5, self::NOW);

        self::assertSame(['groups' => ['3', '7', '8', '9'], 'auto' => ['7', '8', '9'], 'at' => self::NOW], $this->member());
    }

    private function assigner(): NetGroupAssigner
    {
        return new NetGroupAssigner($this->connection, $this->logger);
    }

    /**
     * @return array{groups: list<string>, auto: list<string>, at: int|null}
     */
    private function member(): array
    {
        $row = $this->connection->fetchAssociative('SELECT "groups" AS grp, consho_netGroupsAuto, consho_netGroupsAutoAt FROM tl_member WHERE id = 5');
        self::assertIsArray($row);

        return [
            'groups' => array_values(array_map('strval', StringUtil::deserialize($row['grp'], true))),
            'auto' => array_values(array_map('strval', StringUtil::deserialize($row['consho_netGroupsAuto'], true))),
            'at' => null === $row['consho_netGroupsAutoAt'] ? null : (int) $row['consho_netGroupsAutoAt'],
        ];
    }
}

final class InfoSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}

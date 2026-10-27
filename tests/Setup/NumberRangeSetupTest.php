<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Setup\NumberRangeSetup;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class NumberRangeSetupTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_number_range');
    }

    public function testDieTypenKommenAusDenBefundenDiesesShops(): void
    {
        $issues = [
            new ConfigurationIssue(ConfigurationFinding::NumberRangeMissing, ['typeKeys' => 'order,invoice,cancellation'], 1),
            new ConfigurationIssue(ConfigurationFinding::RefundRangeMissing, ['typeKeys' => 'creditNote,cancellation'], 1),
            new ConfigurationIssue(ConfigurationFinding::NumberRangeMissing, ['typeKeys' => 'order'], 2),
            new ConfigurationIssue(ConfigurationFinding::MailTransportMissing, ['typeKeys' => 'deliveryNote'], 1),
        ];

        self::assertSame(['order', 'invoice', 'cancellation', 'creditNote'], NumberRangeSetup::missing(1, $issues));
        self::assertSame(['order'], NumberRangeSetup::missing(2, $issues));
        self::assertSame([], NumberRangeSetup::missing(3, $issues));
    }

    public function testLegtNurFehlendeAnUndAendertNichts(): void
    {
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'order', 'prefix' => 'WEB-', 'current' => 41]);

        $created = (new NumberRangeSetup($this->connection))->create(1, ['order', 'invoice'], 1000);

        self::assertSame(['invoice' => 'RE-'], $created);
        self::assertSame(
            [['type' => 'order', 'prefix' => 'WEB-', 'current' => 41], ['type' => 'invoice', 'prefix' => 'RE-', 'current' => 0]],
            array_map(static fn (array $r): array => ['type' => $r['type'], 'prefix' => $r['prefix'], 'current' => (int) $r['current']], $this->connection->fetchAllAssociative('SELECT type, prefix, current FROM tl_consho_number_range ORDER BY id')),
        );
    }

    public function testEinBelegtesPraefixBekommtDieShopId(): void
    {
        // Otherwise the second shop would start with the notice "same document
        // prefix". Order numbers do not count there, so not here either.
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'invoice', 'prefix' => 're-']);
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'order', 'prefix' => 'B-']);

        $created = (new NumberRangeSetup($this->connection))->create(2, ['order', 'invoice', 'cancellation'], 1000);

        self::assertSame(['order' => 'B-', 'invoice' => '2RE-', 'cancellation' => 'ST-'], $created);
    }

    public function testZweimalKlickenLegtNichtsDoppeltAn(): void
    {
        $setup = new NumberRangeSetup($this->connection);
        $setup->create(1, ['order'], 1000);

        self::assertSame([], $setup->create(1, ['order'], 1000));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_number_range'));
    }
}

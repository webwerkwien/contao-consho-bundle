<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerGroupIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * D-4 (K5): The mandatory field only takes effect on saving — a shop from
 * before D-4 has no base group until then. A notice that blocks nothing.
 */
final class CustomerGroupIssueSourceTest extends TestCase
{
    public function testOhneOderMitVerschwundenerGruppeEinHinweisJeShop(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_shop');
        $connection->executeStatement("CREATE TABLE tl_member_group (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '')");
        $connection->insert('tl_member_group', ['id' => 4, 'name' => 'Kunden']);
        $connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Ohne', 'customerGroup' => 0]);
        $connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Mit', 'customerGroup' => 4]);
        $connection->insert('tl_consho_shop', ['id' => 3, 'title' => 'Gelöscht', 'customerGroup' => 9]);

        $issues = (new CustomerGroupIssueSource($connection))->issues();

        self::assertCount(2, $issues);
        self::assertSame(ConfigurationFinding::CustomerGroupMissing, $issues[0]->finding);
        self::assertSame(['Ohne', 'Gelöscht'], array_map(static fn ($i): string => $i->context['shop'], $issues));
        self::assertFalse(ConfigurationFinding::CustomerGroupMissing->isWarning(), 'ein Hinweis, keine Warnung');
    }
}

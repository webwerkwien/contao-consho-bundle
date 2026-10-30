<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Customer\NetGroupVatIdIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Automatically assigned net price groups on a member whose VAT ID is no
 * longer valid — and only while the member still holds one of them.
 */
final class NetGroupVatIdIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_member');
    }

    public function testUngueltigeUidMitAutomatischerGruppe(): void
    {
        $this->member(5, 'Muster GmbH', 'DE123456789', 'invalid', ['3', '8'], ['8']);

        $issues = (new NetGroupVatIdIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::NetGroupVatIdLost, $issues[0]->finding);
        self::assertSame(['count' => '1', 'members' => 'Muster GmbH'], $issues[0]->context);
    }

    public function testGeloeschteUid(): void
    {
        $this->member(5, '', '', '', ['8'], ['8']);

        self::assertCount(1, (new NetGroupVatIdIssueSource($this->connection))->issues());
    }

    public function testKeinHinweis(): void
    {
        // Valid VAT ID, group removed by hand, no marker (confirmed in the back end), not reachable.
        $this->member(5, 'A', 'DE1', 'valid', ['8'], ['8']);
        $this->member(6, 'B', 'DE2', 'invalid', ['3'], ['8']);
        $this->member(7, 'C', 'DE3', 'invalid', ['8'], null);
        $this->member(8, 'D', 'DE4', 'unavailable', ['8'], ['8']);

        self::assertSame([], (new NetGroupVatIdIssueSource($this->connection))->issues());
    }

    public function testOhneFirmaDerNameSonstDerBenutzername(): void
    {
        $this->member(5, '', '', 'invalid', ['8'], ['8'], 'Erika', 'Muster');
        $this->member(6, '', '', 'invalid', ['8'], ['8']);

        self::assertSame('Erika Muster, kunde6', (new NetGroupVatIdIssueSource($this->connection))->issues()[0]->context['members']);
    }

    /**
     * @param list<string>      $groups
     * @param list<string>|null $auto
     */
    private function member(int $id, string $company, string $vatId, string $status, array $groups, array|null $auto, string $firstname = '', string $lastname = ''): void
    {
        $this->connection->insert('tl_member', [
            'id' => $id,
            'username' => 'kunde'.$id,
            'company' => $company,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'consho_vatId' => $vatId,
            'consho_vatIdStatus' => $status,
            '"groups"' => serialize($groups),
            'consho_netGroupsAuto' => null === $auto ? null : serialize($auto),
        ]);
    }
}

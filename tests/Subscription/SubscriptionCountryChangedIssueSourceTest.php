<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCountryChangedIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-Nachlese, Review S1: Abo-Kunden mit anderem Land im Konto als auf der
 * letzten Rechnung des Abos.
 */
final class SubscriptionCountryChangedIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, country TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop']);
    }

    public function testNurLaufendeAbosMitAnderemLandAlsAufDerLetztenRechnung(): void
    {
        // 1: Kauf AT, Folgebestellung DE, Konto DE → gleich, kein Befund.
        $this->case(1, 'DE', ['AT', 'DE']);
        // 2: Kauf AT, Konto DE → Befund.
        $this->case(2, 'DE', ['AT']);
        // 3: wie 2, aber beendet → kein Befund.
        $this->case(3, 'DE', ['AT'], 'ended');
        // 4: Konto mit Unterteilung, 5: Konto leer → keine Änderung, die Consho übernähme.
        $this->case(4, 'GB-ENG', ['AT']);
        $this->case(5, '', ['AT']);
        // 6: ruhend, Konto kleingeschrieben → Befund.
        $this->case(6, 'it', ['AT'], 'suspended');
        // 7: wie 2, aber eine Dienstleistung — Steuerland bleibt der Shop (DL4).
        $this->case(7, 'DE', ['AT'], kind: 'service');
        // 8: pausiert (P106) → Befund, die nächste Rechnung kommt.
        $this->case(8, 'DE', ['AT'], 'paused');

        $issues = (new SubscriptionCountryChangedIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::SubscriptionCountryChanged, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'numbers' => 'WEB-2-1, WEB-6-1, WEB-8-1'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    /**
     * @param list<string> $orderCountries the purchase first, then follow-ups, oldest first
     */
    private function case(int $id, string $accountCountry, array $orderCountries, string $state = 'active', string $kind = 'digital'): void
    {
        $this->connection->insert('tl_member', ['id' => $id, 'country' => $accountCountry]);

        foreach ($orderCountries as $i => $country) {
            $this->connection->insert('tl_consho_order', [
                'id' => $id * 10 + $i, 'orderNumber' => \sprintf('WEB-%d-%d', $id, $i), 'billingCountry' => $country,
                'subscription' => 0 === $i ? 0 : $id, 'createdAt' => 1_000 + $i,
            ]);
        }

        $this->connection->insert('tl_consho_subscription', [
            'id' => $id, 'subscriptionNumber' => \sprintf('WEB-%d-1', $id), 'shop' => 1, 'member' => $id,
            'firstOrder' => $id * 10, 'state' => $state, 'kind' => $kind,
        ]);
    }
}

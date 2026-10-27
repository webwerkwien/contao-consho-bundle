<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Subscription\SameTermOpenIssueSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Variant A (A1): a rule "renews by the full
 * minimum term each time" is no longer reserved for business customers. The
 * notice names one that is empty (all customers) or contains a group
 * that is a net price group in no shop. Group 3 is the
 * net price group of shop 1 here.
 */
final class SameTermOpenIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription_rule');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'netPriceGroups' => serialize(['3'])]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zweiter', 'netPriceGroups' => null]);
    }

    public function testNurFuerDieNettogruppeIstInOrdnung(): void
    {
        $this->rule(1, 'Jahresabo B2B', 'sameTerm', ['3']);

        self::assertSame([], $this->issues());
    }

    public function testOhneGruppenStehtSieAllenOffen(): void
    {
        $this->rule(1, 'Jahresabo', 'sameTerm', []);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::SubscriptionSameTermOpen, ['rule' => 'Jahresabo'])], $this->issues());
        self::assertFalse(ConfigurationFinding::SubscriptionSameTermOpen->isWarning(), 'ein Hinweis: in einem anderen Land kann es gewollt sein');
    }

    public function testEineGruppeAusserhalbDerNettogruppenOeffnetSie(): void
    {
        $this->rule(1, 'Jahresabo', 'sameTerm', ['3', '4']);

        self::assertCount(1, $this->issues());
    }

    public function testAndereArtenWerdenNichtGemeldet(): void
    {
        $this->rule(1, 'Monatsabo', 'indefinite', []);
        $this->rule(2, 'Endet', 'ends', []);

        self::assertSame([], $this->issues());
    }

    public function testOhneNettogruppeInIrgendeinemShopIstJedeGruppeOffen(): void
    {
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => null], ['id' => 1]);
        $this->rule(1, 'Jahresabo B2B', 'sameTerm', ['3']);

        self::assertCount(1, $this->issues());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new SameTermOpenIssueSource($this->connection, new SubscriptionRules($this->connection)))->issues();
    }

    /**
     * @param list<string> $groups
     */
    private function rule(int $id, string $title, string $renewal, array $groups): void
    {
        $this->connection->insert('tl_consho_subscription_rule', [
            'id' => $id, 'title' => $title, 'renewal' => $renewal, 'termLength' => 1, 'termUnit' => 'year',
            $this->connection->quoteIdentifier('groups') => [] === $groups ? null : serialize($groups),
        ]);
    }
}

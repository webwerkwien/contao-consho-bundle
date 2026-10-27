<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalGroupPriceAboveListIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * A customer group that pays more than everyone else (C-4).
 *
 * Allowed — but worth a notice. What is checked is therefore not whether it
 * may happen, but whether the notice hits the cases in which a member really
 * pays more.
 */
class DbalGroupPriceAboveListIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->insertProduct(['id' => 1, 'title' => 'Kräutersalz']);
        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
    }

    public function testOhneGruppenpreiseGibtEsNichtsZuMelden(): void
    {
        $this->price('10.0000');

        self::assertSame([], $this->issues());
    }

    public function testEinGuenstigererGruppenpreisIstDerNormalfall(): void
    {
        $this->price('10.0000');
        $this->price('8.0000', group: 3);

        self::assertSame([], $this->issues());
    }

    public function testEinAbopreisDerGruppeIstKeinTeurererListenpreis(): void
    {
        // Abo-1 (A2): €12 in a monthly subscription next to a €10 one-off purchase is no comparison.
        $this->price('10.0000');
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '12.0000', 'customerGroup' => 3, 'subscriptionRule' => 5]);

        self::assertSame([], $this->issues());
    }

    public function testEinTeurererGruppenpreisAufDerselbenSchwelleWirdGemeldet(): void
    {
        // The real-world case: list 7.42 — group 8.00.
        $this->price('7.4167');
        $this->price('8.0000', group: 3);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::GroupPriceAboveList, $issues[0]->finding);
        self::assertSame('1', $issues[0]->context['count']);
        self::assertSame('Kräutersalz / Händler (ab 1)', $issues[0]->context['examples']);
    }

    public function testAuchWennDerGruppenpreisErstOberhalbEinerStaffelTeurerWird(): void
    {
        /*
         * ⚠️ The case a simple row comparison missed: the
         * group row (9.00 from 1) is cheaper than the list price (10.00 from
         * 1) — only from 100 pieces, where everyone else pays 7.00, does it become
         * more expensive. The comparison is therefore made at EVERY threshold, and with the
         * real calculator.
         */
        $this->price('10.0000');
        $this->price('7.0000', minQuantity: '100.0000');
        $this->price('9.0000', group: 3);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame('Kräutersalz / Händler (ab 100)', $issues[0]->context['examples']);
    }

    public function testEineEigeneStaffelDerGruppeHaeltDenHinweisFern(): void
    {
        // The same rows as above, plus a group scale from 100 that
        // stays below the public price. Then the member pays more
        // nowhere — and a notice would be wrong.
        $this->price('10.0000');
        $this->price('7.0000', minQuantity: '100.0000');
        $this->price('9.0000', group: 3);
        $this->price('6.5000', group: 3, minQuantity: '100.0000');

        self::assertSame([], $this->issues());
    }

    public function testEineAbgelaufeneGruppenzeileIstNiemandesPreis(): void
    {
        $this->price('7.0000');
        $this->price('9.0000', group: 3, validTo: self::NOW - 1);

        self::assertSame([], $this->issues());
    }

    public function testEineNieGespeichertePreiszeileZaehltNicht(): void
    {
        // tstamp 0 — the same rule as in DbalPriceRepository::findCandidates().
        $this->price('7.0000');
        $this->connection->insert('tl_consho_price', [
            'pid' => 1,
            'tstamp' => 0,
            'currency' => 'EUR',
            'net' => '9.0000',
            'customerGroup' => 3,
            'minQuantity' => '1.0000',
        ]);

        self::assertSame([], $this->issues());
    }

    public function testEineAndereWaehrungMischtSichNichtEin(): void
    {
        // CHF 9 is not more than EUR 10, and the comparison does not even take
        // place: prices in different currencies never meet.
        $this->price('10.0000');
        $this->price('9.0000', group: 3, currency: 'CHF');

        self::assertSame([], $this->issues());
    }

    public function testOhneOeffentlichenPreisGibtEsNichtsZuVergleichen(): void
    {
        // Only a group price: there is no amount the group price
        // could be more expensive THAN.
        $this->price('9.0000', group: 3);

        self::assertSame([], $this->issues());
    }

    public function testJedesProduktUndJedeGruppeZaehltEinmal(): void
    {
        $this->insertProduct(['id' => 2, 'title' => 'Schwedenbitter']);
        $this->insertProduct(['id' => 3, 'title' => 'Ringelblumensalbe']);
        $this->insertProduct(['id' => 4, 'title' => 'Johanniskrautöl']);
        $this->connection->insert('tl_member_group', ['id' => 4, 'name' => 'Großkunden']);

        foreach ([1, 2, 3, 4] as $product) {
            $this->price('7.0000', product: $product);
            $this->price('9.0000', product: $product, group: 3);
            // A second threshold that would hit as well — it must not
            // increase the counter a second time.
            $this->price('9.5000', product: $product, group: 3, minQuantity: '10.0000');
        }

        // The same product, a second group: counts separately.
        $this->price('9.0000', group: 4);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame('5', $issues[0]->context['count']);
        // Three examples, then an ellipsis.
        self::assertStringEndsWith(' …', $issues[0]->context['examples']);
        self::assertSame(3, substr_count($issues[0]->context['examples'], ' / '));
    }

    public function testDerBefundIstEinHinweisUndSperrtNichts(): void
    {
        // A more expensive group price is allowed.
        // A warning would block the checkout of a shop that does exactly
        // what was configured.
        self::assertFalse(ConfigurationFinding::GroupPriceAboveList->isWarning());
    }

    private function price(string $net, int $product = 1, int $group = 0, string $minQuantity = '1.0000', string $currency = 'EUR', int|null $validTo = null): void
    {
        $this->connection->insert('tl_consho_price', [
            'pid' => $product,
            'tstamp' => 1,
            'currency' => $currency,
            'net' => $net,
            'customerGroup' => $group,
            'minQuantity' => $minQuantity,
            'validTo' => $validTo,
        ]);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalGroupPriceAboveListIssueSource($this->connection))->issues();
    }
}

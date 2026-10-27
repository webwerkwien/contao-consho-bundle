<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalCachedCatalogPageIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * "Always load from cache" on a page while the shop prices per
 * customer (C-4).
 *
 * ⚠️ What these tests do NOT show: that Contao then really serves the guest page.
 * That hinges on `Vary: Cookie` in AbstractController and was read up
 * there, not reproduced — a test for it would check our own
 * transcript. What is checked is the decision: when do we report?
 */
class DbalCachedCatalogPageIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        DcaSchema::createCore($this->connection, 'tl_page');

        $this->insertShop(['id' => 1, 'title' => 'Testshop']);
    }

    public function testOhneKundenabhaengigePreiseIstDerHakenHarmlos(): void
    {
        // The state before C-4, and that of every shop without customer groups: everyone
        // sees the same, and that is exactly what the shared cache is for.
        $this->cachedPage(2, 'Katalog');

        self::assertSame([], $this->issues());
    }

    public function testEineNettogruppeMachtDenHakenZumBefund(): void
    {
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->cachedPage(2, 'Katalog');

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::CatalogPageAlwaysCached, $issues[0]->finding);
        self::assertSame(['pages' => 'Katalog'], $issues[0]->context);
    }

    public function testAuchEinGruppenpreisOhneNettogruppeReicht(): void
    {
        // Two ways to customer-specific prices, and this one needs no setting
        // on the shop: the operator creates a price row for a
        // customer group, done.
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '8.0000', 'customerGroup' => 3]);
        $this->cachedPage(2, 'Katalog');

        self::assertCount(1, $this->issues());
    }

    public function testEineNieGespeichertePreiszeileZaehltNicht(): void
    {
        // tstamp 0 — the same rule as in DbalPriceRepository::findCandidates().
        // Otherwise merely opening a price form would report this finding.
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 0, 'currency' => 'EUR', 'net' => '0.0000', 'customerGroup' => 3]);
        $this->cachedPage(2, 'Katalog');

        self::assertSame([], $this->issues());
    }

    public function testOhneGesetztenHakenGibtEsNichtsZuMelden(): void
    {
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'regular', 'title' => 'Katalog', 'includeCache' => 1, 'alwaysLoadFromCache' => 0]);

        self::assertSame([], $this->issues());
    }

    public function testAlleBetroffenenSeitenStehenInEinemBefund(): void
    {
        // One finding, not one per page: the operator goes through the list
        // once, and an acknowledgement that falls apart at the next tick
        // would be right that way — the context carries the pages and with them the
        // fingerprint.
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->cachedPage(2, 'Katalog');
        $this->cachedPage(3, 'Angebote');

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(['pages' => 'Angebote, Katalog'], $issues[0]->context);
    }

    public function testDerBefundIstEinHinweisUndSperrtNichts(): void
    {
        // Every price shown is a real price of this shop — just the
        // wrong one for this visitor. And the operator set the tick
        // themselves.
        self::assertFalse(ConfigurationFinding::CatalogPageAlwaysCached->isWarning());
    }

    public function testOhneUebersteuerteCacheEinstellungenTutDerHakenNichts(): void
    {
        // ⚠️ loadDetails() reads
        // `alwaysLoadFromCache = includeCache ? alwaysLoadFromCache : false`
        // (PageModel, 933). A leftover tick on a page whose
        // "Override cache settings" is off again has no effect at all —
        // and a finding about it would send the operator to a checkbox that
        // is already harmless.
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['3'])], ['id' => 1]);
        $this->connection->insert('tl_page', [
            'id' => 2,
            'type' => 'regular',
            'title' => 'Katalog',
            'includeCache' => 0,
            'alwaysLoadFromCache' => 1,
        ]);

        self::assertSame([], $this->issues());
    }

    private function cachedPage(int $id, string $title): void
    {
        $this->connection->insert('tl_page', [
            'id' => $id,
            'type' => 'regular',
            'title' => $title,
            // Both, because only the pair has an effect.
            'includeCache' => 1,
            'alwaysLoadFromCache' => 1,
        ]);
    }

    /**
     * @return list<\Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalCachedCatalogPageIssueSource($this->connection))->issues();
    }
}

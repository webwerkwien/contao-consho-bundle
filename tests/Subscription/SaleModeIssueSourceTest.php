<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModeIssueSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-1: findings on the sale mode. Type 1 digital, type 2 physical;
 * rule 5 monthly subscription (open-ended), 6 yearly subscription B2B (same term).
 */
class SaleModeIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_subscription_rule');
        DcaSchema::create($this->connection, 'tl_consho_price');

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 5, 'title' => 'Monatsabo', 'renewal' => 'indefinite']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 6, 'title' => 'Jahresabo B2B', 'renewal' => 'sameTerm']);

        $GLOBALS['TL_LANG']['tl_consho_price']['oneTimeSale'] = 'Einmalkauf';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testEinProduktMitAllenPreisenIstInOrdnung(): void
    {
        $this->product(1, 'Theme', rules: ['5']);
        $this->price(1, 0);
        $this->price(1, 5);

        self::assertSame([], $this->issues());
    }

    public function testFehlendePreiseWerdenJeProduktGenannt(): void
    {
        $this->product(1, 'Theme', rules: ['5', '6']);
        $this->price(1, 5);

        self::assertEquals([
            new ConfigurationIssue(ConfigurationFinding::SaleModePriceMissing, ['product' => 'Theme', 'modes' => '„Einmalkauf", „Jahresabo B2B"']),
        ], $this->issues());
    }

    public function testEineNieGespeichertePreiszeileZaehltNicht(): void
    {
        $this->product(1, 'E-Book');
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 0, 'subscriptionRule' => 0]);

        self::assertSame(ConfigurationFinding::SaleModePriceMissing, $this->issues()[0]->finding);
    }

    public function testKoerperlicheUndUnveroeffentlichteProdukteZaehlenNicht(): void
    {
        // Physical: a finding of its own, ProductPriceMissing. Unpublished: not sold.
        $this->product(1, 'Wanderstock', rules: ['5'], type: 2);
        $this->product(2, 'Entwurf', rules: ['5'], published: false);

        self::assertSame([], $this->issues());
    }

    public function testVariantenFolgenDemGrundprodukt(): void
    {
        $this->product(1, 'Theme');
        $this->price(1, 0);
        // The variant has no prices of its own and is not in the list.
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'title' => 'Theme dunkel', 'productType' => 1]);

        self::assertSame([], $this->issues());
    }

    /**
     * Variant A: a "full minimum term" rule without
     * customer groups is open to everyone — no more "consumers
     * excluded" finding, and the product appears in the lists (A3). Whether it
     * should stay open is asked by SameTermOpenIssueSource.
     */
    public function testGleicheLaufzeitOhneGruppenIstOffen(): void
    {
        $this->product(1, 'Plugin', rules: ['6'], once: false);
        $this->price(1, 6);

        self::assertSame([], $this->issues());
        self::assertTrue(ConfigurationFinding::SaleModePriceMissing->isWarning());
    }

    public function testEineRegelFuerVerbraucherGenuegt(): void
    {
        $this->product(1, 'Plugin', rules: ['6', '5'], once: false);
        $this->price(1, 5);
        $this->price(1, 6);

        self::assertSame([], $this->issues());
    }

    /** Abo-2b, P91: only rules for customer groups — the product appears in no list. */
    public function testNurRegelnFuerGruppenFehlenInDerListe(): void
    {
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 7, 'title' => 'Mitglieder', 'renewal' => 'indefinite', $this->connection->quoteIdentifier('groups') => serialize(['3'])]);
        $this->product(1, 'Plugin', rules: ['7'], once: false);
        $this->price(1, 7);

        self::assertEquals([new ConfigurationIssue(ConfigurationFinding::SubscriptionNotListed, ['product' => 'Plugin'])], $this->issues());
        self::assertFalse(ConfigurationFinding::SubscriptionNotListed->isWarning());

        $this->product(2, 'Plugin 2', rules: ['7', '5'], once: false);
        $this->price(2, 7);
        $this->price(2, 5);

        self::assertCount(1, $this->issues(), 'eine Regel für alle genügt');
    }

    /**
     * Abo-2b counted "same term" for everyone as not
     * open. Since variant A (A3) only the groups decide: open, so
     * the product appears in the lists.
     */
    public function testGleicheLaufzeitFuerAlleZaehltJetztAlsOffen(): void
    {
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 7, 'title' => 'Mitglieder', 'renewal' => 'indefinite', $this->connection->quoteIdentifier('groups') => serialize(['3'])]);
        $this->product(1, 'Plugin', rules: ['6', '7'], once: false);
        $this->price(1, 6);
        $this->price(1, 7);

        self::assertSame([], $this->issues());
    }

    public function testMitEinmalkaufSindVerbraucherNichtAusgeschlossen(): void
    {
        $this->product(1, 'Plugin', rules: ['6']);
        $this->price(1, 0);
        $this->price(1, 6);

        self::assertSame([], $this->issues());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new SaleModeIssueSource($this->connection, new SaleModes($this->connection), new SubscriptionRules($this->connection)))->issues();
    }

    /**
     * @param list<string> $rules
     */
    private function product(int $id, string $title, array $rules = [], bool $once = true, int $type = 1, bool $published = true): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'title' => $title,
            'published' => (int) $published,
            'productType' => $type,
            'saleOnce' => (int) $once,
            'saleSubscription' => (int) ([] !== $rules),
            'subscriptionRules' => serialize($rules),
        ]);
    }

    private function price(int $product, int $rule): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => $product, 'tstamp' => 1, 'subscriptionRule' => $rule]);
    }
}

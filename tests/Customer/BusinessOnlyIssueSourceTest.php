<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Customer\BusinessOnlyIssueSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * BB6: a shop selling to businesses only, set up so that others can buy too.
 * Net price group 3 is the shop's sign of a business customer.
 */
class BusinessOnlyIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        unset($GLOBALS['TL_LANG']);

        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::createCore($this->connection, 'tl_page');

        foreach (['tl_consho_shop', 'tl_consho_product', 'tl_consho_product_type', 'tl_consho_product_shop', 'tl_consho_price', 'tl_consho_subscription_rule'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'tstamp' => 1, 'title' => 'B2B', 'customerType' => 'business', 'guestCheckout' => 0, 'netPriceGroups' => serialize(['3'])]);
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'tstamp' => 1, 'title' => 'Software', 'kind' => 'digital']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 1, 'tstamp' => 1, 'title' => 'Jahresabo', $this->connection->quoteIdentifier('groups') => serialize(['3'])]);

        // Sold by subscription only, to group 3 — the consho.eu setup.
        $this->product(10, 'Modul', once: false, rules: [1]);
        $this->price(10, group: 0, rule: 1);
    }

    public function testNurNettoGruppeMeldetNichts(): void
    {
        self::assertSame([], $this->issues());
    }

    public function testGastbestellungMachtDenShopOffen(): void
    {
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 1], ['id' => 1]);

        self::assertSame(['shop' => 'B2B', 'reasons' => 'guest checkout is on'], $this->only()->context);
    }

    public function testAboRegelFuerAlleMachtDenShopOffen(): void
    {
        $this->connection->update('tl_consho_subscription_rule', [$this->connection->quoteIdentifier('groups') => null], ['id' => 1]);

        self::assertStringContainsString('Modul', $this->only()->context['reasons']);
    }

    public function testAboRegelMitFremderGruppeMachtDenShopOffen(): void
    {
        $this->connection->update('tl_consho_subscription_rule', [$this->connection->quoteIdentifier('groups') => serialize(['2', '3'])], ['id' => 1]);

        self::assertStringContainsString('Modul', $this->only()->context['reasons']);
    }

    /**
     * The rule is open, but the only price is for group 3.
     */
    public function testPreisNurFuerNettoGruppeSchliesstDieOffeneRegel(): void
    {
        $this->connection->update('tl_consho_subscription_rule', [$this->connection->quoteIdentifier('groups') => null], ['id' => 1]);
        $this->connection->update('tl_consho_price', ['customerGroup' => 3], ['pid' => 10]);

        self::assertSame([], $this->issues());
    }

    public function testEinmalkaufFuerAlleMachtDenShopOffen(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->price(11, group: 0, rule: 0);

        self::assertStringContainsString('Theme', $this->only()->context['reasons']);
    }

    /**
     * A one-time price at an article sold by subscription only is never
     * offered.
     */
    public function testEinmalpreisOhneEinmalkaufZaehltNicht(): void
    {
        $this->price(10, group: 0, rule: 0);

        self::assertSame([], $this->issues());
    }

    public function testVariantePreisZaehltBeimHauptprodukt(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->connection->insert('tl_consho_product', ['id' => 12, 'pid' => 11, 'tstamp' => 1, 'title' => 'Theme Pro', 'published' => 1]);
        $this->price(12, group: 2, rule: 0);

        self::assertStringContainsString('Theme', $this->only()->context['reasons']);
    }

    public function testUnveroeffentlichtUndFremderShopZaehlenNicht(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->price(11, group: 0, rule: 0);
        $this->connection->update('tl_consho_product', ['published' => 0], ['id' => 11]);
        $this->product(13, 'Anderswo', once: true, rules: [], shop: 2);
        $this->price(13, group: 0, rule: 0);

        self::assertSame([], $this->issues());
    }

    public function testUnveroeffentlichteVarianteZaehltNicht(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->connection->insert('tl_consho_product', ['id' => 12, 'pid' => 11, 'tstamp' => 1, 'title' => 'Theme Pro', 'published' => 0]);
        $this->price(12, group: 2, rule: 0);

        self::assertSame([], $this->issues());
    }

    public function testArtikelErstAbMorgenZaehltNicht(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->price(11, group: 0, rule: 0);
        $this->connection->update('tl_consho_product', ['start' => (string) (time() + 86_400)], ['id' => 11]);

        self::assertSame([], $this->issues());
    }

    public function testPreisErstAbMorgenZaehltNicht(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->price(11, group: 0, rule: 0);
        $this->connection->update('tl_consho_price', ['validFrom' => time() + 86_400], ['pid' => 11]);

        self::assertSame([], $this->issues());
    }

    /**
     * A price row whose mask was opened but never saved (tstamp 0) sells
     * nothing.
     */
    public function testNieGespeicherterPreisZaehltNicht(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->price(11, group: 0, rule: 0);
        $this->connection->update('tl_consho_price', ['tstamp' => 0], ['pid' => 11]);

        self::assertSame([], $this->issues());
    }

    /**
     * An empty entry in the net price groups is no group: a price for
     * everyone stays open.
     */
    public function testLeererEintragInDenNettoGruppenIstKeineGruppe(): void
    {
        $this->connection->update('tl_consho_shop', ['netPriceGroups' => serialize(['', '3'])], ['id' => 1]);
        $this->product(11, 'Theme', once: true, rules: []);
        $this->price(11, group: 0, rule: 0);

        self::assertStringContainsString('Theme', $this->only()->context['reasons']);
    }

    public function testAbgelaufenerPreisZaehltNicht(): void
    {
        $this->product(11, 'Theme', once: true, rules: []);
        $this->price(11, group: 0, rule: 0);
        $this->connection->update('tl_consho_price', ['validTo' => 1000], ['pid' => 11]);

        self::assertSame([], $this->issues());
    }

    public function testVerbraucherShopWirdNichtGeprueft(): void
    {
        $this->connection->update('tl_consho_shop', ['customerType' => 'consumer', 'guestCheckout' => 1], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    public function testSeiteVertragWiderrufenIstEinHinweis(): void
    {
        $this->connection->insert('tl_page', ['id' => 1, 'pid' => 0, 'tstamp' => 1, 'type' => 'root', 'title' => 'consho.eu', 'consho_shop' => 1, 'consho_withdrawalFormPage' => 5]);
        $this->connection->insert('tl_page', ['id' => 2, 'pid' => 0, 'tstamp' => 1, 'type' => 'root', 'title' => 'ohne', 'consho_shop' => 1, 'consho_withdrawalFormPage' => 0]);

        $issue = $this->only();

        self::assertSame(ConfigurationFinding::BusinessWithdrawalPageSet, $issue->finding);
        self::assertSame(['shop' => 'B2B', 'roots' => 'consho.eu'], $issue->context);
        self::assertFalse($issue->finding->isWarning());
    }

    public function testDieOffeneEinrichtungIstEineWarnung(): void
    {
        self::assertTrue(ConfigurationFinding::BusinessShopOpen->isWarning());
    }

    /**
     * @param list<int> $rules
     */
    private function product(int $id, string $title, bool $once, array $rules, int $shop = 1): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => 0,
            'tstamp' => 1,
            'title' => $title,
            'published' => 1,
            'productType' => 1,
            'saleOnce' => $once ? 1 : 0,
            'saleSubscription' => [] === $rules ? 0 : 1,
            'subscriptionRules' => [] === $rules ? null : serialize(array_map('strval', $rules)),
        ]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => $shop]);
    }

    private function price(int $product, int $group, int $rule): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => $product, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '10.0000', 'customerGroup' => $group, 'subscriptionRule' => $rule]);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new BusinessOnlyIssueSource($this->connection, new SaleModes($this->connection), new SubscriptionRules($this->connection)))->issues();
    }

    private function only(): ConfigurationIssue
    {
        $issues = $this->issues();
        self::assertCount(1, $issues);

        return $issues[0];
    }
}

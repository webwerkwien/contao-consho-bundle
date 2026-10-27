<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Contao\FrontendUser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudience;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Who is being looked at, and what that means for the price (C-4).
 *
 * A real FrontendUser instead of a mock, as in CartResolverTest and for the same
 * reason: the resolver decides on `instanceof FrontendUser`, and a mock
 * of another class would bypass that branch.
 */
class PriceAudienceResolverTest extends TestCase
{
    use CatalogSchema;

    private const SHOP = 1;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
        $this->connection->insert('tl_member_group', ['id' => 4, 'name' => 'Großkunden']);
        $this->connection->insert('tl_member_group', ['id' => 9, 'name' => 'Newsletter']);

        // An article that belongs to shop 1 — without it no price row hangs
        // on a shop, and the notice would rightly stay silent.
        $this->insertProduct(['id' => 1, 'title' => 'Kräutersalz', 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => self::SHOP]);

        // There are prices for 3 and 4, not for 9 — the notice depends on that.
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '8.0000', 'customerGroup' => 3]);
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '7.0000', 'customerGroup' => 4]);
    }

    public function testEinGastHatKeineGruppeSiehtBruttoUndBekommtKeinenHinweis(): void
    {
        $this->shop([3]);

        $audience = $this->resolve(null);

        self::assertSame([], $audience->groups);
        self::assertFalse($audience->showsNet);
        self::assertFalse($audience->hasOwnPrices());
    }

    public function testEinMitgliedOhneGruppeIstEinGast(): void
    {
        $this->shop([3]);

        self::assertEquals(PriceAudience::guest(), $this->resolve([]));
    }

    public function testDieGruppenDesMitgliedsGehenAnDieAufloesung(): void
    {
        $this->shop();

        self::assertSame([3, 4], $this->resolve([3, 4])->groups);
    }

    public function testNurEineAusgewaehlteGruppeMachtDieAnzeigeNetto(): void
    {
        $this->shop([3]);

        self::assertTrue($this->resolve([3])->showsNet);
        self::assertFalse($this->resolve([4])->showsNet);
        // One of the two is enough.
        self::assertTrue($this->resolve([4, 3])->showsNet);
    }

    public function testOhneAuswahlAmShopSiehtNiemandNetto(): void
    {
        // The normal case of a freshly created shop: nothing configured,
        // so gross for everyone — also for a member with a group price.
        $this->shop();

        self::assertFalse($this->resolve([3, 4])->showsNet);
    }

    public function testEineGeloeschteGruppeInDerAuswahlWirdUebergangen(): void
    {
        // The case DbalNetPriceGroupIssueSource reports. All that counts here is
        // that the request goes through: a front-end request must not depend
        // on whether the operator has read the finding.
        $this->shop([99]);

        self::assertFalse($this->resolve([3])->showsNet);
    }

    public function testDerShopEntscheidetUeberDieAnzeigeNichtDieGruppe(): void
    {
        // The same member, two shops: a merchant at one, a private customer at the
        // other. A switch on tl_member_group could not do that.
        $this->shop([3], 1);
        $this->shop([], 2);

        self::assertTrue($this->resolve([3], 1)->showsNet);
        self::assertFalse($this->resolve([3], 2)->showsNet);
    }

    public function testDerHinweisNenntNurGruppenDieWirklichPreiseHaben(): void
    {
        $this->shop([3]);

        // 9 is a newsletter group without a single price row. If it were
        // in the notice, the notice would send the visitor on the product page looking for a
        // price of their own that does not exist.
        self::assertSame('Händler', $this->resolve([3, 9])->groupLabel);
        self::assertSame('', $this->resolve([9])->groupLabel);
        self::assertFalse($this->resolve([9])->hasOwnPrices());
    }

    public function testMehrereGruppenMitPreisenStehenAlleImHinweis(): void
    {
        $this->shop([3]);

        self::assertSame('Großkunden, Händler', $this->resolve([3, 4])->groupLabel);
    }

    public function testEineNieGespeichertePreiszeileBegruendetKeinenHinweis(): void
    {
        // tstamp 0 — the form was opened and never saved. The same
        // rule as in DbalPriceRepository::findCandidates().
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 0, 'currency' => 'EUR', 'net' => '0.0000', 'customerGroup' => 9]);
        $this->shop([3]);

        self::assertSame('', $this->resolve([9])->groupLabel);
    }

    public function testDerHinweisHaengtNichtAmNettoStatus(): void
    {
        // Group 4 has prices but is not in netPriceGroups: gross,
        // and still there is a price of their own to find.
        $this->shop([3]);

        $audience = $this->resolve([4]);

        self::assertFalse($audience->showsNet);
        self::assertTrue($audience->hasOwnPrices());
    }

    public function testDerHinweisSchweigtZuGruppenpreisenEinesAnderenShops(): void
    {
        // A group price in the other invoicing entity is
        // not this visitor's price here — the notice would send them looking
        // for nothing on this product page.
        $this->shop([3], 1);
        $this->shop([3], 2);

        self::assertSame('Händler', $this->resolve([3], 1)->groupLabel);
        self::assertSame('', $this->resolve([3], 2)->groupLabel);
    }

    public function testDerHinweisSchweigtZuEinerAnderenWaehrung(): void
    {
        // The pound catalogue says nothing about euro rows: under /en/ there is
        // no price of their own to find for this member.
        $this->shop([3]);

        self::assertSame('Händler', $this->resolve([3], currency: 'EUR')->groupLabel);
        self::assertSame('', $this->resolve([3], currency: 'GBP')->groupLabel);
    }

    public function testDerHinweisSchweigtZuAbgelaufenenZeilen(): void
    {
        // An expired promotion is no longer a price of their own. From
        // inclusive to exclusive — the price repository's rule.
        $this->connection->update('tl_consho_price', ['validFrom' => 500, 'validTo' => 900], ['customerGroup' => 3]);
        $this->shop([3]);

        self::assertSame('Händler', $this->resolve([3], now: 899)->groupLabel);
        self::assertSame('', $this->resolve([3], now: 900)->groupLabel);
    }

    public function testEineVarianteErbtDieShopZuordnungIhresHauptprodukts(): void
    {
        // The price row hangs on the variant, the shop assignment on the
        // main product (A, 3.7 "the variant inherits"). A comparison only via
        // the variant would find nothing and wrongly stay silent.
        $this->insertProduct(['id' => 5, 'pid' => 1, 'title' => '250 g', 'published' => 1]);
        $this->connection->delete('tl_consho_price', ['customerGroup' => 3]);
        $this->connection->insert('tl_consho_price', ['pid' => 5, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '8.0000', 'customerGroup' => 3]);
        $this->shop([3]);

        self::assertSame('Händler', $this->resolve([3])->groupLabel);
    }

    public function testEineEingeschraenkteVarianteSchweigtImAusgeschlossenenShop(): void
    {
        /*
         * ⚠️ The opposite direction to the
         * test above: the main product is in shop 1 and 2, the variant
         * restricts to shop 2 (A, 3.7 "intersected"). A group price on
         * this variant is not a price anyone reaches in shop 1 —
         * the notice spoke there nevertheless and sent people looking for nothing.
         */
        $this->insertShop(['id' => 2, 'title' => 'Testshop 2']);
        $this->insertProductShop(['pid' => 1, 'shop' => 2]);

        $this->insertProduct(['id' => 5, 'pid' => 1, 'title' => '250 g', 'published' => 1]);
        $this->insertProductShop(['pid' => 5, 'shop' => 2]);

        $this->connection->delete('tl_consho_price', ['customerGroup' => 3]);
        $this->connection->insert('tl_consho_price', ['pid' => 5, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '8.0000', 'customerGroup' => 3]);

        self::assertSame('Händler', $this->resolve([3], 2)->groupLabel);
        self::assertSame('', $this->resolve([3], 1)->groupLabel);
    }

    public function testDieGruppenKommenAuchAlsArrayAn(): void
    {
        /*
         * ⚠️ The production form, and for a long time seen by no
         * test: FrontendUser::setUserFromDb() unserialises `groups`
         * itself and intersects the selection with the ACTIVE groups. What
         * arrives here is therefore an array with possibly gappy
         * keys — not the string all the other tests feed in.
         *
         * This also gives the answer for the disabled group: Contao
         * has long cut it out before the resolver sees it.
         */
        $this->shop([3]);

        $storage = new TokenStorage();
        $user = (new ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = 1;
        $user->username = 'member1';
        $user->groups = [1 => '3', 3 => '4'];
        $storage->setToken(new UsernamePasswordToken($user, 'frontend'));

        $audience = (new PriceAudienceResolver($storage, $this->connection))->forShop(self::SHOP, 'EUR', 1_000);

        self::assertSame([3, 4], $audience->groups);
        self::assertTrue($audience->showsNet);
    }

    /**
     * @param list<int> $netGroups
     */
    private function shop(array $netGroups = [], int $id = self::SHOP): void
    {
        $this->insertShop([
            'id' => $id,
            'title' => 'Testshop '.$id,
            // As Contao stores it: serialize() over strings.
            'netPriceGroups' => [] === $netGroups ? null : serialize(array_map('strval', $netGroups)),
        ]);
    }

    /**
     * @param list<int>|null $groups null = nobody logged in
     */
    private function resolve(array|null $groups, int $shopId = self::SHOP, string $currency = 'EUR', int $now = 1_000): PriceAudience
    {
        return (new PriceAudienceResolver($this->tokens($groups), $this->connection))->forShop($shopId, $currency, $now);
    }

    /**
     * @param list<int>|null $groups
     */
    private function tokens(array|null $groups): TokenStorageInterface
    {
        $storage = new TokenStorage();

        if (null === $groups) {
            return $storage;
        }

        // A real FrontendUser without the framework: the constructor is protected
        // (singleton), newInstanceWithoutConstructor() bypasses it. The
        // username must be set because UsernamePasswordToken asks for it on
        // construction.
        $user = (new ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = 1;
        $user->username = 'member1';
        $user->groups = serialize(array_map('strval', $groups));

        $storage->setToken(new UsernamePasswordToken($user, 'frontend'));

        return $storage;
    }
}

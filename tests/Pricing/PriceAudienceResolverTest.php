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
 * Wer angesehen wird, und was das für den Preis heißt (C-4).
 *
 * Echter FrontendUser statt Mock, wie in CartResolverTest und aus demselben
 * Grund: Der Resolver entscheidet an `instanceof FrontendUser`, und ein Mock
 * einer anderen Klasse liefe an diesem Zweig vorbei.
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

        // Ein Artikel, der zu Shop 1 gehört — ohne ihn hängt keine Preiszeile
        // an einem Shop, und der Hinweis schwiege zu Recht.
        $this->insertProduct(['id' => 1, 'title' => 'Kräutersalz', 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => self::SHOP]);

        // Preise gibt es für 3 und 4, nicht für 9 — daran hängt der Hinweis.
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
        // Eine von zweien reicht.
        self::assertTrue($this->resolve([4, 3])->showsNet);
    }

    public function testOhneAuswahlAmShopSiehtNiemandNetto(): void
    {
        // Der Normalfall eines frisch angelegten Shops: nichts eingestellt,
        // also brutto für alle — auch für ein Mitglied mit Gruppenpreis.
        $this->shop();

        self::assertFalse($this->resolve([3, 4])->showsNet);
    }

    public function testEineGeloeschteGruppeInDerAuswahlWirdUebergangen(): void
    {
        // Der Fall, den DbalNetPriceGroupIssueSource meldet. Hier zählt nur,
        // dass die Anfrage durchläuft: Ein Frontend-Aufruf darf nicht davon
        // abhängen, ob der Betreiber den Befund gelesen hat.
        $this->shop([99]);

        self::assertFalse($this->resolve([3])->showsNet);
    }

    public function testDerShopEntscheidetUeberDieAnzeigeNichtDieGruppe(): void
    {
        // Dasselbe Mitglied, zwei Shops: Händler beim einen, Privatkunde beim
        // anderen. Ein Schalter an tl_member_group könnte das nicht.
        $this->shop([3], 1);
        $this->shop([], 2);

        self::assertTrue($this->resolve([3], 1)->showsNet);
        self::assertFalse($this->resolve([3], 2)->showsNet);
    }

    public function testDerHinweisNenntNurGruppenDieWirklichPreiseHaben(): void
    {
        $this->shop([3]);

        // 9 ist eine Newslettergruppe ohne eine einzige Preiszeile. Stünde sie
        // im Hinweis, schickte er den Besucher auf der Produktseite nach einem
        // eigenen Preis, den es nicht gibt.
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
        // tstamp 0 — die Maske wurde geöffnet und nie gespeichert. Dieselbe
        // Regel wie in DbalPriceRepository::findCandidates().
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 0, 'currency' => 'EUR', 'net' => '0.0000', 'customerGroup' => 9]);
        $this->shop([3]);

        self::assertSame('', $this->resolve([9])->groupLabel);
    }

    public function testDerHinweisHaengtNichtAmNettoStatus(): void
    {
        // Gruppe 4 hat Preise, steht aber nicht in netPriceGroups: brutto,
        // und trotzdem gibt es einen eigenen Preis zu finden.
        $this->shop([3]);

        $audience = $this->resolve([4]);

        self::assertFalse($audience->showsNet);
        self::assertTrue($audience->hasOwnPrices());
    }

    public function testDerHinweisSchweigtZuGruppenpreisenEinesAnderenShops(): void
    {
        // Ein Gruppenpreis in der anderen rechnungsstellenden Einheit ist
        // nicht der Preis dieses Besuchers hier — der Hinweis schickte ihn auf
        // dieser Produktseite nach nichts.
        $this->shop([3], 1);
        $this->shop([3], 2);

        self::assertSame('Händler', $this->resolve([3], 1)->groupLabel);
        self::assertSame('', $this->resolve([3], 2)->groupLabel);
    }

    public function testDerHinweisSchweigtZuEinerAnderenWaehrung(): void
    {
        // Der Pfund-Katalog sagt nichts über Euro-Zeilen: Unter /en/ gibt es
        // für dieses Mitglied keinen eigenen Preis zu finden.
        $this->shop([3]);

        self::assertSame('Händler', $this->resolve([3], currency: 'EUR')->groupLabel);
        self::assertSame('', $this->resolve([3], currency: 'GBP')->groupLabel);
    }

    public function testDerHinweisSchweigtZuAbgelaufenenZeilen(): void
    {
        // Eine ausgelaufene Aktion ist kein eigener Preis mehr. Von
        // einschließlich bis ausschließlich — die Regel des Preis-Repositorys.
        $this->connection->update('tl_consho_price', ['validFrom' => 500, 'validTo' => 900], ['customerGroup' => 3]);
        $this->shop([3]);

        self::assertSame('Händler', $this->resolve([3], now: 899)->groupLabel);
        self::assertSame('', $this->resolve([3], now: 900)->groupLabel);
    }

    public function testEineVarianteErbtDieShopZuordnungIhresHauptprodukts(): void
    {
        // Die Preiszeile hängt an der Variante, die Shop-Zuordnung am
        // Hauptprodukt (A, 3.7 „die Variante erbt"). Ein Vergleich nur über
        // die Variante fände nichts und schwiege zu Unrecht.
        $this->insertProduct(['id' => 5, 'pid' => 1, 'title' => '250 g', 'published' => 1]);
        $this->connection->delete('tl_consho_price', ['customerGroup' => 3]);
        $this->connection->insert('tl_consho_price', ['pid' => 5, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '8.0000', 'customerGroup' => 3]);
        $this->shop([3]);

        self::assertSame('Händler', $this->resolve([3])->groupLabel);
    }

    public function testEineEingeschraenkteVarianteSchweigtImAusgeschlossenenShop(): void
    {
        /*
         * ⚠️ In der zweiten Reviewrunde gefunden, und die Gegenrichtung zum
         * Test darüber: Das Hauptprodukt steht in Shop 1 und 2, die Variante
         * schränkt auf Shop 2 ein (A, 3.7 „geschnitten"). Ein Gruppenpreis an
         * dieser Variante ist in Shop 1 kein Preis, den irgendjemand erreicht —
         * der Hinweis sprach dort trotzdem und schickte nach nichts.
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
         * ⚠️ Die Produktionsform, und bis zum Abschlussreview C-4 von keinem
         * Test gesehen: FrontendUser::setUserFromDb() deserialisiert `groups`
         * selbst und schneidet die Auswahl mit den AKTIVEN Gruppen. Was hier
         * ankommt, ist also ein Array mit möglicherweise lückenhaften
         * Schlüsseln — nicht die Zeichenkette, die alle anderen Tests füttern.
         *
         * Daraus folgt auch die Antwort auf die deaktivierte Gruppe: Contao
         * hat sie längst herausgeschnitten, bevor der Resolver sie sieht.
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
            // Wie Contao es ablegt: serialize() über Zeichenketten.
            'netPriceGroups' => [] === $netGroups ? null : serialize(array_map('strval', $netGroups)),
        ]);
    }

    /**
     * @param list<int>|null $groups null = niemand angemeldet
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

        // Echter FrontendUser ohne Framework: der Konstruktor ist protected
        // (Singleton), newInstanceWithoutConstructor() umgeht ihn. Der
        // Benutzername muss stehen, weil UsernamePasswordToken ihn beim Bau
        // abfragt.
        $user = (new ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = 1;
        $user->username = 'member1';
        $user->groups = serialize(array_map('strval', $groups));

        $storage->setToken(new UsernamePasswordToken($user, 'frontend'));

        return $storage;
    }
}

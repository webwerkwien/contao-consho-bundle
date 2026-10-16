<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitle;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Gegen SQLite mit dem gemeinsamen Katalog-Testschema.
 *
 * Die Einfüger des Traits liefern keine Id zurück (`insertProduct(): void`),
 * deshalb stehen die Ids hier als Konstanten.
 */
class CartTitlesTest extends TestCase
{
    use CatalogSchema;

    private const MAIN = 1;
    private const VARIANT = 2;
    private const OTHER = 3;

    private CartTitles $titles;

    protected function setUp(): void
    {
        $this->createCatalogSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->titles = new CartTitles($this->catalogConnection);
    }

    public function testEinHauptproduktTraegtSeinenEigenenTitel(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);

        self::assertSame([self::MAIN => 'Wanderstock'], $this->titlesOf([self::MAIN], '', ''));
    }

    public function testEineVarianteTraegtDenTitelIhresHauptprodukts(): void
    {
        // Der eigene Titel einer Variante ist eine Backend-Beschriftung
        // (ProductDetailAssembler: „title … always the main product's").
        // Stünde er im Warenkorb, hieße der Artikel dort anders als im Katalog.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm']);

        self::assertSame([self::VARIANT => 'Wanderstock'], $this->titlesOf([self::VARIANT], '', ''));
    }

    public function testZweiVariantenDesselbenProduktsSindAuseinanderzuhalten(): void
    {
        // Der Befund aus der Durchsicht: Beide Zeilen tragen den Titel des
        // Hauptprodukts, also hießen Beschriftung und Knöpfe in beiden Zeilen
        // gleich — zweimal „Menge: Wanderstock", zweimal „Entfernen
        // Wanderstock". Für einen Screenreader sind das dieselben Namen.
        // Die Artikelnummer steht am Artikel selbst und wird nie vom
        // Hauptprodukt geerbt (tl_consho_product: „Per ARTICLE, and never
        // inherited"), ist also genau das zeilenspezifische Merkmal.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock', 'sku' => 'WS']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm', 'sku' => 'WS-120-ROT']);
        $this->insertProduct(['id' => self::OTHER, 'pid' => self::MAIN, 'title' => 'rot / 140 cm', 'sku' => 'WS-140-ROT']);

        $titles = $this->titles->of([self::VARIANT, self::OTHER], '', '');

        // Der Titel bleibt der des Hauptprodukts — das ist die Regel, nicht
        // der Fehler.
        self::assertSame('Wanderstock', $titles[self::VARIANT]->title);
        self::assertSame('Wanderstock', $titles[self::OTHER]->title);

        self::assertSame('WS-120-ROT', $titles[self::VARIANT]->sku);
        self::assertSame('WS-140-ROT', $titles[self::OTHER]->sku);
        self::assertNotSame($titles[self::VARIANT]->sku, $titles[self::OTHER]->sku);
    }

    public function testEineVarianteNenntIhreWerteInDerReihenfolgeDerAchsen(): void
    {
        // E2 (Michael, 2026-10-08: „nur die Werte"): „Wanderstock, rot, L",
        // Achsen wie auf der Detailseite nach ihrer Beschriftung geordnet —
        // hier also Farbe vor Größe, obwohl Größe zuerst angelegt ist.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / L']);
        $this->insertPropertyGroup(['id' => 10, 'title' => 'Größe']);
        $this->insertPropertyGroup(['id' => 11, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => 100, 'pid' => 10, 'title' => 'L']);
        $this->insertPropertyOption(['id' => 110, 'pid' => 11, 'title' => 'rot']);
        $this->insertProductOption(['pid' => self::VARIANT, 'optionId' => 100]);
        $this->insertProductOption(['pid' => self::VARIANT, 'optionId' => 110]);

        self::assertSame(
            [self::MAIN => 'Wanderstock', self::VARIANT => 'Wanderstock, rot, L'],
            $this->titlesOf([self::MAIN, self::VARIANT], '', ''),
        );
    }

    public function testJeAchseZaehltNurEinWert(): void
    {
        // Wie auf der Detailseite gewinnt bei zwei Werten einer Achse der
        // erste nach `sorting`.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN]);
        $this->insertPropertyGroup(['id' => 11, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => 110, 'pid' => 11, 'title' => 'rot', 'sorting' => 20]);
        $this->insertPropertyOption(['id' => 111, 'pid' => 11, 'title' => 'blau', 'sorting' => 10]);
        $this->insertProductOption(['pid' => self::VARIANT, 'optionId' => 110]);
        $this->insertProductOption(['pid' => self::VARIANT, 'optionId' => 111]);

        self::assertSame([self::VARIANT => 'Wanderstock, blau'], $this->titlesOf([self::VARIANT], '', ''));
    }

    public function testDieWerteStehenInDerSpracheDesWarenkorbs(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN]);
        $this->insertPropertyGroup(['id' => 11, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => 110, 'pid' => 11, 'title' => 'rot']);
        $this->insertProductOption(['pid' => self::VARIANT, 'optionId' => 110]);
        $this->insertTranslation(['pid' => self::MAIN, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => 'Hiking pole']);
        $this->insertTranslation(['pid' => 110, 'ptable' => 'tl_consho_property_option', 'language' => 'en', 'title' => 'red']);

        self::assertSame([self::VARIANT => 'Hiking pole, red'], $this->titlesOf([self::VARIANT], 'en', 'de'));
    }

    public function testInEinerZweitsspracheGiltDieUebersetzungDesHauptprodukts(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm']);
        $this->insertTranslation([
            'pid' => self::MAIN,
            'ptable' => 'tl_consho_product',
            'language' => 'en',
            'title' => 'Hiking pole',
        ]);

        self::assertSame(
            [self::MAIN => 'Hiking pole', self::VARIANT => 'Hiking pole'],
            $this->titlesOf([self::MAIN, self::VARIANT], 'en', 'de'),
        );
    }

    public function testEineLeereUebersetzungFaelltAufDenEigenenTextZurueck(): void
    {
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);
        $this->insertTranslation(['pid' => self::MAIN, 'ptable' => 'tl_consho_product', 'language' => 'en', 'title' => '']);

        self::assertSame([self::MAIN => 'Wanderstock'], $this->titlesOf([self::MAIN], 'en', 'de'));
    }

    public function testEinGeloeschtesProduktFehltInDerZuordnung(): void
    {
        // Genau der Fall, für den die unbepreisbaren Zeilen da sind: Das
        // Produkt ist weg, die Position steht noch im Warenkorb. Es gibt
        // keinen Titel — die Vorlage setzt dann ihren Ersatztext ein.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);

        self::assertSame([self::MAIN => 'Wanderstock'], $this->titlesOf([self::MAIN, self::OTHER], '', ''));
    }

    public function testEineVarianteOhneHauptproduktFaelltAufDenErsatztextZurueck(): void
    {
        // Der INNER JOIN aufs Hauptprodukt lässt die Zeile ganz weg, wenn das
        // Hauptprodukt gelöscht ist — obwohl der Artikel selbst noch existiert.
        // Gewollt: Der Titel, den die Vorlage zeigen soll, IST der des
        // Hauptprodukts (ProductDetailAssembler), und den gibt es dann nicht
        // mehr. Der eigene Titel der Variante („rot / 120 cm") wäre eine
        // Backend-Beschriftung, der Ersatztext ist ehrlicher. Die Zeile bleibt
        // sichtbar und entfernbar, weil die Vorlage den Ersatztext einsetzt.
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm', 'sku' => 'WS-120-ROT']);

        self::assertSame([], $this->titles->of([self::VARIANT], '', ''));
    }

    public function testOhneArtikelWirdNichtGefragt(): void
    {
        self::assertSame([], $this->titles->of([], '', ''));
    }

    public function testEineUnveroeffentlichteZeileBehaeltIhrenNamen(): void
    {
        // Ohne Sichtbarkeitsprüfung, ausdrücklich: Wer nicht sieht, welcher
        // Artikel den Checkout blockiert, kann ihn auch nicht entfernen.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock', 'published' => 0]);

        self::assertSame([self::MAIN => 'Wanderstock'], $this->titlesOf([self::MAIN], '', ''));
    }

    /**
     * Nur die Titel, damit die Erwartungen oben so knapp bleiben wie vor der
     * Artikelnummer.
     *
     * @param list<int> $articleIds
     *
     * @return array<int, string>
     */
    private function titlesOf(array $articleIds, string $language, string $fallback): array
    {
        return array_map(
            static fn (CartTitle $name): string => $name->title,
            $this->titles->of($articleIds, $language, $fallback),
        );
    }
}

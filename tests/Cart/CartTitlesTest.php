<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitle;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Against SQLite with the shared catalogue test schema.
 *
 * The trait's inserters return no id (`insertProduct(): void`),
 * which is why the ids are constants here.
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
        // A variant's own title is a back-end label
        // (ProductDetailAssembler: "title … always the main product's").
        // If it appeared in the cart, the article would be called differently there than in the catalogue.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm']);

        self::assertSame([self::VARIANT => 'Wanderstock'], $this->titlesOf([self::VARIANT], '', ''));
    }

    public function testZweiVariantenDesselbenProduktsSindAuseinanderzuhalten(): void
    {
        // The problem: both lines carry the title of the
        // main product, so label and buttons in both lines were
        // the same — twice "Quantity: Wanderstock", twice "Remove
        // Wanderstock". For a screen reader those are the same names.
        // The article number sits on the article itself and is never inherited
        // from the main product (tl_consho_product: "Per ARTICLE, and never
        // inherited"), so it is exactly the line-specific feature.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock', 'sku' => 'WS']);
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm', 'sku' => 'WS-120-ROT']);
        $this->insertProduct(['id' => self::OTHER, 'pid' => self::MAIN, 'title' => 'rot / 140 cm', 'sku' => 'WS-140-ROT']);

        $titles = $this->titles->of([self::VARIANT, self::OTHER], '', '');

        // The title stays that of the main product — that is the rule, not
        // the bug.
        self::assertSame('Wanderstock', $titles[self::VARIANT]->title);
        self::assertSame('Wanderstock', $titles[self::OTHER]->title);

        self::assertSame('WS-120-ROT', $titles[self::VARIANT]->sku);
        self::assertSame('WS-140-ROT', $titles[self::OTHER]->sku);
        self::assertNotSame($titles[self::VARIANT]->sku, $titles[self::OTHER]->sku);
    }

    public function testEineVarianteNenntIhreWerteInDerReihenfolgeDerAchsen(): void
    {
        // E2: "Wanderstock, rot, L",
        // axes ordered by their label as on the detail page —
        // here colour before size, although size was created first.
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
        // As on the detail page, with two values on one axis the
        // first by `sorting` wins.
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
        // Exactly the case the unpriceable lines exist for: the
        // product is gone, the line item is still in the cart. There is
        // no title — the template then uses its fallback text.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock']);

        self::assertSame([self::MAIN => 'Wanderstock'], $this->titlesOf([self::MAIN, self::OTHER], '', ''));
    }

    public function testEineVarianteOhneHauptproduktFaelltAufDenErsatztextZurueck(): void
    {
        // The INNER JOIN on the main product leaves the row out entirely when the
        // main product is deleted — even though the article itself still exists.
        // Intended: the title the template is meant to show IS the one of the
        // main product (ProductDetailAssembler), and that no longer exists
        // then. The variant's own title ("rot / 120 cm") would be a
        // back-end label; the fallback text is more honest. The line stays
        // visible and removable because the template uses the fallback text.
        $this->insertProduct(['id' => self::VARIANT, 'pid' => self::MAIN, 'title' => 'rot / 120 cm', 'sku' => 'WS-120-ROT']);

        self::assertSame([], $this->titles->of([self::VARIANT], '', ''));
    }

    public function testOhneArtikelWirdNichtGefragt(): void
    {
        self::assertSame([], $this->titles->of([], '', ''));
    }

    public function testEineUnveroeffentlichteZeileBehaeltIhrenNamen(): void
    {
        // Without a visibility check, explicitly: whoever cannot see which
        // article blocks the checkout cannot remove it either.
        $this->insertProduct(['id' => self::MAIN, 'title' => 'Wanderstock', 'published' => 0]);

        self::assertSame([self::MAIN => 'Wanderstock'], $this->titlesOf([self::MAIN], '', ''));
    }

    /**
     * Only the titles, so that the expectations above stay as short as before
     * the article number.
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

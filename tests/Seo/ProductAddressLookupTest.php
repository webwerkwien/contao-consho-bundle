<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressLookup;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class ProductAddressLookupTest extends TestCase
{
    private const NOW = 1_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_shop', 'tl_consho_manufacturer', 'tl_consho_product_type', 'tl_consho_translation'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_manufacturer', ['id' => 7, 'alias' => 'kraeuterhaus']);
        $this->connection->insert('tl_consho_product_type', ['id' => 4, 'alias' => 'bitter']);
    }

    public function testFindsAProductByItsAlias(): void
    {
        $this->product(1, alias: 'schwedenbitter');

        self::assertSame(1, $this->find(['alias' => 'schwedenbitter']));
    }

    public function testFindsAProductBySku(): void
    {
        $this->product(1, alias: 'schwedenbitter', sku: 'SB-250');

        self::assertSame(1, $this->find(['sku' => 'SB-250']));
    }

    public function testEverySegmentInTheAddressHasToMatch(): void
    {
        /*
         * The point of the class. With the pattern {manufacturer}/{alias} the
         * alias alone would already find the product, so /shop/anything/
         * schwedenbitter would answer 200 too — an unbounded supply of
         * addresses for one product.
         */
        $this->product(1, alias: 'schwedenbitter', manufacturer: 7);

        self::assertSame(1, $this->find(['manufacturer' => 'kraeuterhaus', 'alias' => 'schwedenbitter']));
        self::assertNull($this->find(['manufacturer' => 'irgendwas', 'alias' => 'schwedenbitter']));
    }

    public function testAProductWithoutAManufacturerAnswersOnTheShortenedAddress(): void
    {
        // ProductPathTemplate::render() drops the empty segment, so the address
        // carries no manufacturer — and the lookup must agree with that.
        $this->product(1, alias: 'schwedenbitter');

        self::assertSame(1, $this->find(['alias' => 'schwedenbitter']));
        self::assertNull($this->find(['manufacturer' => 'kraeuterhaus', 'alias' => 'schwedenbitter']));
    }

    public function testAVariantHasNoAddressOfItsOwn(): void
    {
        // Not by a rule of its own: mainProduct() requires pid = 0.
        $this->product(1, alias: 'schwedenbitter');
        $this->product(2, pid: 1, alias: 'schwedenbitter-250');

        self::assertNull($this->find(['alias' => 'schwedenbitter-250']));
    }

    public function testAnUnpublishedProductIsNotFound(): void
    {
        $this->product(1, alias: 'schwedenbitter', published: false);

        self::assertNull($this->find(['alias' => 'schwedenbitter']));
    }

    public function testAProductOfAnotherShopIsNotFound(): void
    {
        $this->product(1, alias: 'schwedenbitter', shop: 2);

        self::assertNull($this->find(['alias' => 'schwedenbitter'], shop: 1));
        self::assertSame(1, $this->find(['alias' => 'schwedenbitter'], shop: 2));
    }

    public function testEmptySegmentsFindNothingRatherThanTheFirstProduct(): void
    {
        /*
         * Cannot happen through a validated template, but an empty condition
         * list would have returned whatever came first — a wrong product page
         * answering 200, which is worse than an error.
         */
        $this->product(1, alias: 'schwedenbitter');

        self::assertNull($this->find([]));
    }

    // ── Language (M-3) ───────────────────────────────────────────────────

    public function testItFindsByTheTranslatedAlias(): void
    {
        $this->product(1, alias: 'kraeuterbitter');
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame(1, $this->find(['alias' => 'herbal-bitters'], language: 'en', fallback: 'de'));
    }

    public function testTheFallbackAliasDoesNotResolveInAnotherLanguage(): void
    {
        /*
         * The most important test: otherwise every address is reachable twice —
         * under the German AND the English alias.
         */
        $this->product(1, alias: 'kraeuterbitter');
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertNull($this->find(['alias' => 'kraeuterbitter'], language: 'en', fallback: 'de'));
    }

    public function testInTheFallbackLanguageTheRecordsOwnAliasIsUsed(): void
    {
        $this->product(1, alias: 'kraeuterbitter');
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame(1, $this->find(['alias' => 'kraeuterbitter'], language: 'de', fallback: 'de'));
    }

    public function testTheSkuSegmentIsNeverTranslated(): void
    {
        $this->product(1, alias: 'kraeuterbitter', sku: 'SB-250');
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame(1, $this->find(['alias' => 'kraeuterbitter', 'sku' => 'SB-250'], language: 'de', fallback: 'de'));
        self::assertSame(1, $this->find(['alias' => 'herbal-bitters', 'sku' => 'SB-250'], language: 'en', fallback: 'de'));
    }

    public function testWithoutALanguageNothingChanges(): void
    {
        /*
         * The touchstone: without a language exactly the previous behaviour,
         * even if translations exist. In the default language
         * TranslationJoin::sql() returns an empty string — no additional
         * join is created.
         */
        $this->product(1, alias: 'kraeuterbitter');
        $this->translate(1, 'en', alias: 'herbal-bitters');

        self::assertSame(1, $this->find(['alias' => 'kraeuterbitter']));
        self::assertNull($this->find(['alias' => 'herbal-bitters']));
    }

    /**
     * @param array<string, string> $segments
     */
    private function find(array $segments, int $shop = 1, string $language = '', string $fallback = ''): ?int
    {
        return (new ProductAddressLookup($this->connection, new VisibilityCriteria()))
            ->find($segments, $shop, self::NOW, $language, $fallback)
        ;
    }

    private function translate(int $pid, string $language, ?string $alias, string $ptable = 'tl_consho_product'): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid,
            'ptable' => $ptable,
            'language' => $language,
            'alias' => $alias,
        ]);
    }

    private function product(
        int $id,
        int $pid = 0,
        string $alias = '',
        string $sku = '',
        int $manufacturer = 0,
        bool $published = true,
        int $shop = 1,
    ): void {
        $this->connection->insert('tl_consho_product', [
            'id' => $id,
            'pid' => $pid,
            'alias' => $alias,
            'sku' => $sku,
            'manufacturer' => $manufacturer,
            'productType' => 4,
            'published' => $published ? 1 : 0,
        ]);

        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => $shop]);
    }
}

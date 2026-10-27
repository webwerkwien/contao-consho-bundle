<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\CatalogPriceRules;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ResolvedPrice;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block H, H-3: catalogue discount rules (H5, C1, C2) — applied to the price the
 * rows have decided on.
 */
class CatalogPriceRulesTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_price_rule', 'tl_consho_product', 'tl_consho_product_property', 'tl_consho_product_type'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // 1: manufacturer 3, type 4, property 50. 2: manufacturer 9. 5: variant of 1 with property 60.
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'manufacturer' => 3, 'productType' => 4]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 0, 'manufacturer' => 9]);
        $this->connection->insert('tl_consho_product', ['id' => 5, 'pid' => 1]);
        $this->connection->insert('tl_consho_product_property', ['pid' => 1, 'optionId' => 50]);
        $this->connection->insert('tl_consho_product_property', ['pid' => 5, 'optionId' => 60]);
    }

    public function testEineRegelFuerAlleMachtDenPreisZurAktion(): void
    {
        $this->rule(['percent' => '20']);

        $price = $this->apply(1);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::Promotion, $price->source);
    }

    /**
     * WG8: a rule for everything does not hit the voucher — nobody said to sell
     * money below its value. Nor the variant.
     */
    public function testEinGutscheinWirdNieReduziert(): void
    {
        $this->connection->insert('tl_consho_product_type', ['id' => 6, 'title' => 'Gutschein', 'kind' => 'voucher']);
        $this->connection->insert('tl_consho_product', ['id' => 7, 'pid' => 0, 'productType' => 6]);
        $this->connection->insert('tl_consho_product', ['id' => 8, 'pid' => 7]);
        $this->rule(['percent' => '20']);

        self::assertSame(['10.0000', PriceSource::List], [$this->apply(7)->net, $this->apply(7)->source]);
        self::assertSame('10.0000', $this->apply(8, 7)->net);
        self::assertSame('8.0000', $this->apply(1)->net, 'die Ware daneben weiter');
    }

    public function testOhneRegelBleibtDerPreis(): void
    {
        $price = $this->apply(1);

        self::assertSame('10.0000', $price->net);
        self::assertSame(PriceSource::List, $price->source);
    }

    public function testHerstellerTypUndProdukt(): void
    {
        $this->rule(['percent' => '20', 'manufacturers' => serialize(['3'])]);
        self::assertSame('8.0000', $this->apply(1)->net);
        self::assertSame('10.0000', $this->apply(2)->net);
        self::assertSame('8.0000', $this->apply(5, 1)->net, 'die Variante über ihr Produkt');

        $this->reset(['percent' => '20', 'productTypes' => serialize(['4'])]);
        self::assertSame('8.0000', $this->apply(1)->net);
        self::assertSame('10.0000', $this->apply(2)->net);

        $this->reset(['percent' => '20', 'products' => serialize(['2'])]);
        self::assertSame('10.0000', $this->apply(1)->net);
        self::assertSame('8.0000', $this->apply(2)->net);
    }

    public function testAlleAusgefuelltenBedingungenMuessenPassen(): void
    {
        $this->rule(['percent' => '20', 'manufacturers' => serialize(['3']), 'productTypes' => serialize(['99'])]);

        self::assertSame('10.0000', $this->apply(1)->net);
    }

    public function testEigenschaftDesProduktsUndDerVariante(): void
    {
        $this->rule(['percent' => '20', 'propertyOptions' => serialize(['60'])]);
        self::assertSame('10.0000', $this->apply(1)->net);
        self::assertSame('8.0000', $this->apply(5, 1)->net, 'die eigene Eigenschaft der Variante');

        $this->reset(['percent' => '20', 'propertyOptions' => serialize(['50'])]);
        self::assertSame('8.0000', $this->apply(5, 1)->net, 'die Eigenschaft ihres Produkts');
    }

    public function testKundengruppen(): void
    {
        $this->rule(['percent' => '20', 'memberGroups' => serialize(['7'])]);

        self::assertSame('10.0000', $this->apply(1)->net, 'nicht für Gäste');
        self::assertSame('8.0000', $this->apply(1, groups: [7])->net);
        self::assertSame('10.0000', $this->apply(1, groups: [8])->net);
    }

    public function testEineGruppenregelAufDemListenpreisTraegtDieGruppe(): void
    {
        // H: otherwise only the net comparison in ComparePrices would keep the strike-through price away.
        $this->rule(['percent' => '20', 'memberGroups' => serialize(['9', '7'])]);

        self::assertSame(7, $this->apply(1, groups: [3, 7, 9])->customerGroup);
        self::assertSame(8, $this->apply(1, groups: [7], price: new ResolvedPrice('10.0000', 'EUR', PriceSource::CustomerGroup, '1', 8))->customerGroup, 'eine Gruppe am Preis bleibt');

        $this->reset(['percent' => '20']);
        self::assertNull($this->apply(1, groups: [7])->customerGroup, 'eine Regel für alle bleibt ohne Gruppe');
    }

    public function testDieGewinnendeGruppeBleibtAmPreis(): void
    {
        $this->rule(['percent' => '20']);

        $price = $this->apply(1, groups: [7], price: new ResolvedPrice('9.0000', 'EUR', PriceSource::CustomerGroup, '1', 7));

        self::assertSame('7.2000', $price->net);
        self::assertSame(PriceSource::Promotion, $price->source);
        self::assertSame(7, $price->customerGroup, 'ohne Streichpreis — er gehört dem Gastpreis');
    }

    public function testBereitsReduzierteWareNurMitHaekchen(): void
    {
        $promotion = new ResolvedPrice('9.0000', 'EUR', PriceSource::Promotion, '1');

        $this->rule(['percent' => '20']);
        self::assertSame('9.0000', $this->apply(1, price: $promotion)->net);

        $this->reset(['percent' => '20', 'reduced' => 1]);
        self::assertSame('7.2000', $this->apply(1, price: $promotion)->net);
    }

    public function testMehrereRegelnDieGuenstigsteKeineSumme(): void
    {
        $this->rule(['percent' => '10']);
        $this->rule(['percent' => '25']);
        $this->rule(['percent' => '15']);

        self::assertSame('7.5000', $this->apply(1)->net);
    }

    public function testZeitraumUndAktiv(): void
    {
        $this->rule(['percent' => '20', 'validFrom' => self::NOW, 'validTo' => self::NOW + 100]);
        $this->rule(['percent' => '50', 'published' => 0]);

        self::assertSame('10.0000', $this->apply(1, at: self::NOW - 1)->net);
        self::assertSame('8.0000', $this->apply(1, at: self::NOW)->net, 'ab einschließlich');
        self::assertSame('10.0000', $this->apply(1, at: self::NOW + 100)->net, 'bis ausschließlich');
    }

    public function testDieGrenzenFuerDenCron(): void
    {
        $this->rule(['percent' => '20', 'manufacturers' => serialize(['3']), 'validFrom' => self::NOW + 50]);
        $this->rule(['percent' => '20', 'products' => serialize(['2']), 'validTo' => self::NOW + 500]);

        $rules = new CatalogPriceRules($this->connection);

        self::assertSame([1], $rules->productIdsWithBoundary(self::NOW, self::NOW + 60));
        self::assertSame([], $rules->productIdsWithBoundary(self::NOW + 50, self::NOW + 60), '(last, now] — der Beginn genau bei last ist schon übernommen');
        self::assertSame([1, 2], $rules->productIdsWithBoundary(self::NOW, self::NOW + 500));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function rule(array $values): void
    {
        $this->connection->insert('tl_consho_price_rule', ['tstamp' => 1, 'title' => 'Regel', 'published' => 1, ...$values]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function reset(array $values): void
    {
        $this->connection->executeStatement('DELETE FROM tl_consho_price_rule');
        $this->rule($values);
    }

    /**
     * @param list<int> $groups
     */
    private function apply(int $article, int|null $main = null, array $groups = [], ResolvedPrice|null $price = null, int $at = self::NOW): ResolvedPrice
    {
        // Fresh every time: the class reads the rules once per request.
        return (new CatalogPriceRules($this->connection))->apply($article, $main ?? $article, $price ?? new ResolvedPrice('10.0000', 'EUR', PriceSource::List, '1'), $groups, $at);
    }
}

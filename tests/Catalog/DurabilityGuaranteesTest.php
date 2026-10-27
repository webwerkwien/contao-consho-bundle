<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityGuarantees;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block GARAN: from the main product, only for goods, only whole.
 * Type 1 physical, 2 digital.
 */
class DurabilityGuaranteesTest extends TestCase
{
    private const LABEL = "\x01\x23\x45\x67\x89\xab\xcd\xef\x01\x23\x45\x67\x89\xab\xcd\xef";

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'digital']);
    }

    public function testDieVarianteErbtVomHauptprodukt(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'durabilityYears' => 5, 'durabilityLabel' => self::LABEL]);
        // Own values on the variant do not count (GA2) — the mask does not show them there.
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'durabilityYears' => 9]);

        $label = StringUtil::binToUuid(self::LABEL);

        self::assertSame([1 => ['years' => '5', 'label' => $label, 'nested' => null], 2 => ['years' => '5', 'label' => $label, 'nested' => null]], $this->of([1, 2]));
    }

    /**
     * GA8: the nested label comes along from the main product, only next to a
     * full label and only from a public folder.
     */
    public function testDasGeschachtelteLabelKommtMit(): void
    {
        $nested = "\xfe\xdc\xba\x98\x76\x54\x32\x10\xfe\xdc\xba\x98\x76\x54\x32\x10";
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'durabilityYears' => 5, 'durabilityLabel' => self::LABEL, 'durabilityNested' => $nested]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        // Without a full label nothing — not the nested one either.
        $this->connection->insert('tl_consho_product', ['id' => 3, 'productType' => 1, 'durabilityYears' => 5, 'durabilityNested' => $nested]);

        $files = $this->createMock(PublicFiles::class);
        $files->method('isPublic')->willReturnCallback(static fn (string $uuid): bool => self::LABEL === $uuid);

        $all = $this->of([1, 2, 3]);
        $guarded = (new DurabilityGuarantees($this->connection, $files))->of([1, 2]);

        self::assertSame([1, 2], array_keys($all));
        self::assertSame(StringUtil::binToUuid($nested), $all[2]['nested'], 'die Variante erbt');
        self::assertSame(['years' => '5', 'label' => StringUtil::binToUuid(self::LABEL), 'nested' => null], $guarded[1] ?? [], 'nicht öffentlich: das volle Label bleibt, das geschachtelte fällt weg');
    }

    public function testNurGanzUndNurFuerWaren(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'durabilityYears' => 5]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'productType' => 1, 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'productType' => 2, 'durabilityYears' => 5, 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 4, 'productType' => 1, 'durabilityYears' => 2, 'durabilityLabel' => self::LABEL]);
        // Without a type it is goods.
        $this->connection->insert('tl_consho_product', ['id' => 5, 'durabilityYears' => 3, 'durabilityLabel' => self::LABEL]);

        self::assertSame([5], array_keys($this->of([1, 2, 3, 4, 5])));
    }

    /**
     * GA9: 2.5 years are more than two; "5.0", as MySQL returns a decimal
     * column, arrives as "5"; a decimal place the label does not know
     * is not a duration.
     */
    public function testHalbeJahre(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'durabilityYears' => '2.5', 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'durabilityYears' => '5.0', 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'durabilityYears' => '4.2', 'durabilityLabel' => self::LABEL]);

        self::assertSame(['2.5', '5'], array_column($this->of([1, 2, 3]), 'years'));
    }

    /**
     * GARAN: the rule of DbalArticleShippingData — type of the
     * main product, otherwise its own. Under a main product without a type
     * a digital variant is not goods.
     */
    public function testEineDigitaleVarianteUnterEinemHauptproduktOhneTyp(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'durabilityYears' => 5, 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'productType' => 2]);

        self::assertSame([1], array_keys($this->of([1, 2])));
    }

    /**
     * A label in a protected folder is gone everywhere —
     * page, checkout and freezing see the same answer.
     */
    public function testEinNichtOeffentlichesLabelGiltNicht(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'durabilityYears' => 5, 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);

        $files = $this->createMock(PublicFiles::class);
        $files->expects(self::once())->method('isPublic')->with(self::LABEL)->willReturn(false);

        self::assertSame([], (new DurabilityGuarantees($this->connection, $files))->of([1, 2]), 'einmal gefragt, für beide Artikel');
    }

    public function testOhneIdsKeineAbfrage(): void
    {
        self::assertSame([], (new DurabilityGuarantees($this->createMock(Connection::class)))->of([]));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{years: string, label: string, nested: string|null}>
     */
    private function of(array $ids): array
    {
        $guarantees = (new DurabilityGuarantees($this->connection))->of($ids);
        ksort($guarantees);

        return $guarantees;
    }
}

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
 * Block GARAN: vom Hauptprodukt, nur für Waren, nur ganz.
 * Typ 1 körperlich, 2 digital.
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
        // Eigene Werte an der Variante zählen nicht (GA2) — die Maske zeigt sie dort nicht.
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'durabilityYears' => 9]);

        $label = StringUtil::binToUuid(self::LABEL);

        self::assertSame([1 => ['years' => '5', 'label' => $label, 'nested' => null], 2 => ['years' => '5', 'label' => $label, 'nested' => null]], $this->of([1, 2]));
    }

    /**
     * GA8: das geschachtelte Label kommt vom Hauptprodukt mit, nur neben einem
     * vollen Label und nur aus einem öffentlichen Ordner.
     */
    public function testDasGeschachtelteLabelKommtMit(): void
    {
        $nested = "\xfe\xdc\xba\x98\x76\x54\x32\x10\xfe\xdc\xba\x98\x76\x54\x32\x10";
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'durabilityYears' => 5, 'durabilityLabel' => self::LABEL, 'durabilityNested' => $nested]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        // Ohne volles Label nichts — auch nicht das geschachtelte.
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
        // Ohne Typ ist es eine Ware.
        $this->connection->insert('tl_consho_product', ['id' => 5, 'durabilityYears' => 3, 'durabilityLabel' => self::LABEL]);

        self::assertSame([5], array_keys($this->of([1, 2, 3, 4, 5])));
    }

    /**
     * GA9: 2,5 Jahre sind mehr als zwei; „5.0", wie MySQL eine Dezimalspalte
     * liefert, kommt als „5" an; eine Kommastelle, die das Label nicht kennt,
     * ist keine Dauer.
     */
    public function testHalbeJahre(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'durabilityYears' => '2.5', 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'durabilityYears' => '5.0', 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'durabilityYears' => '4.2', 'durabilityLabel' => self::LABEL]);

        self::assertSame(['2.5', '5'], array_column($this->of([1, 2, 3]), 'years'));
    }

    /**
     * Review GARAN, 1: die Regel von DbalArticleShippingData — Typ des
     * Hauptprodukts, sonst der eigene. Unter einem Hauptprodukt ohne Typ ist
     * eine digitale Variante keine Ware.
     */
    public function testEineDigitaleVarianteUnterEinemHauptproduktOhneTyp(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 1, 'durabilityYears' => 5, 'durabilityLabel' => self::LABEL]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'productType' => 2]);

        self::assertSame([1], array_keys($this->of([1, 2])));
    }

    /**
     * Abnahme c5: ein Label in einem geschützten Ordner ist überall weg —
     * Seite, Kasse und Einfrieren sehen dieselbe Antwort.
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

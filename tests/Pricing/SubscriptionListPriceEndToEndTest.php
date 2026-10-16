<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

/**
 * Abo-2b, Ende zu Ende: echte Preistabelle, echte Verkaufsarten und Regeln,
 * echter Projektor — was die Projektion je Produkt schreibt.
 *
 * Gebaut, weil die Lücke genau zwischen den Teilen lag: Der Resolver kannte
 * das erste Modell, aber die Projektion fragte ihre Produkte und Währungen nur
 * unter den Einmalkauf-Zeilen — ein reines Abo-Produkt wäre nie gerechnet
 * worden, und jeder Einzeltest wäre grün geblieben.
 */
class SubscriptionListPriceEndToEndTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_subscription_rule', 'tl_consho_price', 'tl_consho_product_list_price', 'tl_consho_projection_state'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $groups = $this->connection->quoteIdentifier('groups');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'service']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 1, 'title' => 'Monatsabo', 'billingInterval' => 1, 'renewal' => 'indefinite']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 2, 'title' => 'Jahresabo', 'billingInterval' => 12, 'renewal' => 'ends']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 3, 'title' => 'Mitglieder', 'billingInterval' => 1, 'renewal' => 'indefinite', $groups => serialize(['7'])]);

        // 1: nur Abo, Jahresabo zuerst gewählt — ohne jede Einmalkauf-Zeile,
        // sonst fände die Projektion es auch auf dem alten Weg.
        $this->product(1, once: false, rules: ['2', '1']);
        $this->price(1, '1.0000', 1);
        $this->price(1, '10.0000', 2);

        // 2: Einmalkauf und Abo.
        $this->product(2, once: true, rules: ['1']);
        $this->price(2, '9.0000');
        $this->price(2, '1.0000', 1);

        // 3: nur ein Modell für eine Kundengruppe.
        $this->product(3, once: false, rules: ['3']);
        $this->price(3, '1.0000', 3);
    }

    public function testDieProjektionSchreibtJeProduktDasErsteAngebotFuerAlle(): void
    {
        $this->projector()->rebuild(self::NOW);

        $rows = [];

        foreach ($this->connection->fetchAllAssociative('SELECT pid, net, gross, subscriptionRule, alsoSubscription FROM tl_consho_product_list_price ORDER BY pid') as $row) {
            $rows[(int) $row['pid']] = [(float) $row['net'], (float) $row['gross'], (int) $row['subscriptionRule'], (bool) $row['alsoSubscription']];
        }

        self::assertSame([
            1 => [10.0, 12.0, 2, false],
            2 => [9.0, 10.8, 0, true],
        ], $rows, 'Produkt 3 fehlt: sein einziges Modell steht nicht allen offen (P91)');

        // Ein zweiter Lauf findet nichts zu ändern. Nicht verify(): das
        // vergleicht Zeichenketten, und SQLite streicht die Nullen (Fall 30).
        self::assertSame(0, $this->projector()->refresh([1, 2, 3], self::NOW));
    }

    public function testEinGeaendertesModellIstEineAenderung(): void
    {
        $this->projector()->rebuild(self::NOW);

        // Jetzt Monatsabo zuerst: derselbe Produktsatz, eine andere Zahl.
        $this->connection->update('tl_consho_product', ['subscriptionRules' => serialize(['1', '2'])], ['id' => 1]);

        self::assertSame(1, $this->projector()->refresh([1], self::NOW), 'nur das Modell ändert sich — der Vergleich muss es sehen');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT subscriptionRule FROM tl_consho_product_list_price WHERE pid = 1'));
    }

    /**
     * @param list<string> $rules
     */
    private function product(int $id, bool $once, array $rules): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id, 'pid' => 0, 'title' => 'Produkt '.$id, 'productType' => 1, 'taxClass' => 1,
            'saleOnce' => $once ? 1 : 0, 'saleSubscription' => 1, 'subscriptionRules' => serialize($rules),
        ]);
    }

    private function price(int $productId, string $net, int $rule = 0): void
    {
        $this->connection->insert('tl_consho_price', ['pid' => $productId, 'tstamp' => 1, 'currency' => 'EUR', 'net' => $net, 'subscriptionRule' => $rule]);
    }

    private function projector(): ListPriceProjector
    {
        $repository = new DbalPriceRepository($this->connection);

        $taxClasses = $this->createMock(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(1);

        $articles = $this->createMock(ArticleTreeInterface::class);
        $articles->method('articlesOf')->willReturnCallback(static fn (array $ids): array => array_combine($ids, $ids));

        $markets = $this->createMock(MarketRepositoryInterface::class);
        $markets->method('findMarkets')->willReturn([new Market('EUR', 'AT')]);

        return new ListPriceProjector(
            $repository,
            new ArticlePriceResolver(
                new PriceCalculator($repository),
                $repository,
                new BasePriceCalculator(),
                $this->grossCalculator(),
                $taxClasses,
                new SubscriptionOffers(new SaleModes($this->connection), new SubscriptionRules($this->connection)),
            ),
            new SqliteProjectionWriter($this->connection),
            $articles,
            $markets,
        );
    }
}

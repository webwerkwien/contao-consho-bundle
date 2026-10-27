<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;
use Webwerkwien\ContaoConshoBundle\Catalog\TilePrices;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionTerms;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * Abo-2b, P88: the price of the tile as a buyer
 * reads it — with real rules from the table and the real language files.
 */
class TilePricesTest extends TestCase
{
    private Connection $connection;

    /** Counts the SELECTs on the rules — the class answers for a whole page. */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            public int $ruleSelects = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $sql = ltrim((string) ($context['sql'] ?? ''));

                if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'tl_consho_subscription_rule')) {
                    ++$this->ruleSelects;
                }
            }
        };

        $config = (new \Doctrine\DBAL\Configuration())->setMiddlewares([new Middleware($this->logger)]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        DcaSchema::create($this->connection, 'tl_consho_subscription_rule');
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 1, 'title' => 'Monatsabo', 'billingInterval' => 1, 'renewal' => 'indefinite']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 2, 'title' => 'Jahresabo', 'billingInterval' => 12, 'renewal' => 'ends']);
    }

    public function testEinmalkaufAboUndOhnePreis(): void
    {
        $prices = $this->prices()->formatted([
            new ProductTile(1, 'Salz', '', '', '8.9'),
            new ProductTile(2, 'Mitgliedschaft', '', '', '1.2', subscriptionRule: 1),
            new ProductTile(3, 'Kurs', '', '', '12', subscriptionRule: 2),
            new ProductTile(4, 'Ohne', '', '', ''),
            new ProductTile(5, 'Mitgliedschaft 2', '', '', '2.4', subscriptionRule: 1),
        ], 'EUR', 'de');

        self::assertSame(['8,90 €', '1,20 € / Monat', '12,00 € / Jahr', '', '2,40 € / Monat'], array_map(static fn (string $p): string => str_replace("\u{a0}", ' ', $p), $prices));
        self::assertSame(1, $this->logger->ruleSelects ?? null, 'die Regeln einer Seite in einer Abfrage');
    }

    public function testEineVerschwundeneRegelIstKeinPreis(): void
    {
        // An amount per period without the period would read as a price.
        self::assertSame([''], $this->prices()->formatted([new ProductTile(1, 'Kurs', '', '', '1.2', subscriptionRule: 9)], 'EUR', 'de'));
    }

    /**
     * Block H (H-2): a price is struck through only next to the one-time
     * purchase price of a single item — not next to "ab", not next to a
     * subscription amount.
     */
    public function testStreichpreisNurNebenEinemEinzelnenEinmalkaufPreis(): void
    {
        $compares = $this->prices()->compares([
            new ProductTile(1, 'Essig', '', '', '9.6', compare: '12'),
            new ProductTile(2, 'Bitter', '', '', '9.6', isFrom: true, compare: '12'),
            new ProductTile(3, 'Kurs', '', '', '1.2', subscriptionRule: 1, compare: '2'),
            new ProductTile(4, 'Salz', '', '', '5'),
            new ProductTile(5, 'Ohne Preis', '', '', '', compare: '12'),
        ], 'EUR', 'de');

        self::assertSame(['12,00 €', '', '', '', ''], array_map(static fn (string $s): string => str_replace("\u{a0}", ' ', $s), $compares));
    }

    private function prices(): TilePrices
    {
        return new TilePrices(new MoneyFormatter(), new SubscriptionRules($this->connection), new SubscriptionTerms(new ContaoLikeTranslator()));
    }
}

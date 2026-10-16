<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartTitles;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityGuarantees;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderProductRepository;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Was die Bestellzeile vom Produkt einfriert — mit echtem CartTitles, denn
 * der Name kommt bewusst von dort (Prüfungsnotiz, Regel 25).
 */
class DbalOrderProductRepositoryTest extends TestCase
{
    use CatalogSchema;

    public function testDieBestellzeileFriertDenNamenMitDenWertenDerVarianteEin(): void
    {
        // E2: „Wanderstock, rot" steht als Text in productName — Rechnung,
        // Mails und Backend lesen ihn von dort.
        $this->createCatalogSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->insertProduct(['id' => 1, 'title' => 'Wanderstock']);
        $this->insertProduct(['id' => 2, 'pid' => 1, 'title' => 'rot', 'sku' => 'WS-ROT']);
        $this->insertPropertyGroup(['id' => 11, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => 110, 'pid' => 11, 'title' => 'rot']);
        $this->insertProductOption(['pid' => 2, 'optionId' => 110]);

        $articles = $this->createStub(ArticleShippingDataInterface::class);
        $articles->method('of')->willReturn([]);

        $snapshot = (new DbalOrderProductRepository($this->catalogConnection, new CartTitles($this->catalogConnection), $articles))->snapshot(2);

        self::assertNotNull($snapshot);
        self::assertSame('Wanderstock, rot', $snapshot['name']);
        self::assertSame('WS-ROT', $snapshot['sku']);
        self::assertSame(1, $snapshot['mainProduct']);
    }

    public function testEinLangerNamePasstInDieSpalte(): void
    {
        // Review E2, S1: ein 250 Zeichen langer Titel plus Werte sprengte
        // productName (255) — auf MariaDB im Strict-Modus scheitert der Kauf.
        $this->createCatalogSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->insertProduct(['id' => 1, 'title' => str_repeat('ä', 250)]);
        $this->insertProduct(['id' => 2, 'pid' => 1]);
        $this->insertPropertyGroup(['id' => 11, 'title' => 'Farbe']);
        $this->insertPropertyOption(['id' => 110, 'pid' => 11, 'title' => 'dunkelrot']);
        $this->insertProductOption(['pid' => 2, 'optionId' => 110]);

        $articles = $this->createStub(ArticleShippingDataInterface::class);
        $articles->method('of')->willReturn([]);

        $name = (new DbalOrderProductRepository($this->catalogConnection, new CartTitles($this->catalogConnection), $articles))->snapshot(2)['name'] ?? '';

        self::assertSame(DbalOrderProductRepository::NAME_LENGTH, mb_strlen($name), 'in Zeichen, nicht in Bytes gekürzt');
        self::assertStringStartsWith(str_repeat('ä', 250).', dun', $name);
    }

    /**
     * Block GARAN (GA6): Dauer und Label, wie sie vor dem Knopf standen —
     * aus derselben Quelle wie der Checkout, die Variante erbt.
     */
    public function testDieGarantieDesHauptproduktsWirdEingefroren(): void
    {
        $label = str_repeat("\x07", 16);
        $this->createCatalogSchema(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->insertProduct(['id' => 1, 'title' => 'Akkuschrauber', 'durabilityYears' => 5, 'durabilityLabel' => $label]);
        $this->insertProduct(['id' => 2, 'pid' => 1]);
        $this->insertProduct(['id' => 3, 'title' => 'Seil']);

        $articles = $this->createStub(ArticleShippingDataInterface::class);
        $articles->method('of')->willReturn([]);
        $repository = new DbalOrderProductRepository($this->catalogConnection, new CartTitles($this->catalogConnection), $articles, new DurabilityGuarantees($this->catalogConnection));

        $snapshot = $repository->snapshot(2);
        self::assertSame('5', $snapshot['durabilityYears'] ?? null);
        self::assertSame($label, $snapshot['durabilityLabel'] ?? null);

        $none = $repository->snapshot(3);
        self::assertNotNull($none);
        self::assertSame('0', $none['durabilityYears'] ?? null);
        self::assertArrayHasKey('durabilityLabel', $none);
        self::assertNull($none['durabilityLabel']);
    }
}

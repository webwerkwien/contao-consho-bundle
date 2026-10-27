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
 * What the order line freezes from the product — with the real CartTitles,
 * not a stand-in, because the name deliberately comes from there.
 */
class DbalOrderProductRepositoryTest extends TestCase
{
    use CatalogSchema;

    public function testDieBestellzeileFriertDenNamenMitDenWertenDerVarianteEin(): void
    {
        // E2: "Wanderstock, rot" is stored as text in productName — invoice,
        // mails and backend read it from there.
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
        // E2: a 250-character title plus values overflowed
        // productName (255) — on MariaDB in strict mode the purchase fails.
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
     * Block GARAN (GA6): duration and label as they stood before the button —
     * from the same source as the checkout, the variant inherits.
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

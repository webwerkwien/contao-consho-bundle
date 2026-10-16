<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\StockFieldsListener;
use Webwerkwien\ContaoConshoBundle\Stock\ManualStock;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block G, G-1: Bestand in der Produktmaske — welche Felder wo, und gebucht
 * wird die Differenz zum Wert beim Öffnen (G3).
 */
class StockFieldsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_stock');
        DcaSchema::create($this->connection, 'tl_consho_stock_movement');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'productType' => 1, 'stockTracked' => 1]);

        require __DIR__.'/../../../contao/dca/tl_consho_product.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testDasProduktOhneVariantenZeigtEinstellungenUndBestand(): void
    {
        $this->listener()->onLoad($this->dc(1));

        self::assertStringContainsString('purchaseSteps,stockTracked,stockBackorder,stockWarning,stockTarget,packUnit,supplier,stockQuantity,stockOpened,stockAvailableFrom,supplierSku;', $this->palette());
    }

    public function testOhneBestandNurDasHaekchen(): void
    {
        $this->connection->update('tl_consho_product', ['stockTracked' => 0], ['id' => 1]);

        $this->listener()->onLoad($this->dc(1));

        self::assertStringContainsString('purchaseSteps,stockTracked;', $this->palette());
    }

    public function testMitVariantenDerBestandAnDerVariante(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);

        $this->listener()->onLoad($this->dc(1));
        self::assertStringContainsString('purchaseSteps,stockTracked,stockBackorder,stockWarning,stockTarget,packUnit,supplier;', $this->palette());

        require __DIR__.'/../../../contao/dca/tl_consho_product.php';
        $this->listener()->onLoad($this->dc(2));
        self::assertStringContainsString('purchaseSteps,stockQuantity,stockOpened,stockAvailableFrom,supplierSku;', $this->palette());
    }

    /**
     * G-5 (G10, G11): Digitale Ware führt keinen Bestand von Hand — ihr
     * Bestand sind die Codes. Sie teilt nur die Schwelle, am Hauptprodukt.
     */
    public function testDigitaleWareNurMitSchwelle(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 2], ['id' => 1]);

        $this->listener()->onLoad($this->dc(1));

        self::assertStringContainsString('purchaseSteps,stockWarning;', $this->palette());
        self::assertStringNotContainsString('stockQuantity', $this->palette());
    }

    public function testAusserhalbDerBearbeitungKeineFelder(): void
    {
        $this->listener('editAll')->onLoad($this->dc(1));

        self::assertStringNotContainsString('stock', $this->palette());
    }

    /**
     * G3: Zehn beim Öffnen, dazwischen zwei verkauft, fünfzehn eingegeben —
     * plus fünf, dreizehn auf Lager.
     */
    public function testGebuchtWirdDieDifferenzZumGeoeffnetenWert(): void
    {
        $this->rows()->sync(1, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '10.0000'], ['article' => 1]);

        $field = $this->listener()->openedField($this->dc(1, 'stockOpened'));
        self::assertSame('<input type="hidden" name="stockOpened" value="10">', $field);

        $this->connection->executeStatement('UPDATE tl_consho_stock SET quantity = quantity - 2');

        $this->listener('edit', ['stockOpened' => '10'])->saveQuantity('15', $this->dc(1, 'stockQuantity'));

        self::assertSame('13.0000', StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock')));
        $movement = $this->connection->fetchAssociative('SELECT delta, reason FROM tl_consho_stock_movement');
        self::assertIsArray($movement);
        self::assertSame('5.0000', StockBooking::scaled($movement['delta']));
        self::assertSame('manual', $movement['reason']);
    }

    public function testUnveraendertKeineBewegung(): void
    {
        $this->rows()->sync(1, 100);

        $this->listener('edit', ['stockOpened' => '0'])->saveQuantity('0', $this->dc(1, 'stockQuantity'));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_movement'));
    }

    /**
     * „Mehrere bearbeiten": Contao hängt die id an jeden Feldnamen.
     */
    public function testMehrereBearbeitenLiestDenWertMitDerId(): void
    {
        $this->rows()->sync(1, 100);

        $this->listener('editAll', ['stockOpened_1' => '0', 'stockOpened' => '99'])->saveQuantity('4', $this->dc(1, 'stockQuantity_1'));

        self::assertSame('4.0000', StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock')));
    }

    public function testDasErsteSpeichernLegtDieZeileAn(): void
    {
        $this->listener('edit', ['stockOpened' => ''])->saveQuantity('3', $this->dc(1, 'stockQuantity'));

        self::assertSame('3.0000', StockBooking::scaled($this->connection->fetchOne('SELECT quantity FROM tl_consho_stock WHERE article = 1')));
    }

    public function testKeineZahlWirdAbgelehnt(): void
    {
        $this->rows()->sync(1, 100);

        $this->expectException(\RuntimeException::class);

        $this->listener('edit', ['stockOpened' => '0'])->saveQuantity('viele', $this->dc(1, 'stockQuantity'));
    }

    public function testWiederLieferbarAbSitztAmBestand(): void
    {
        $this->rows()->sync(1, 100);

        $this->listener()->saveAvailableFrom(1_790_000_000, $this->dc(1));
        self::assertSame(1_790_000_000, $this->listener()->loadAvailableFrom(null, $this->dc(1)));

        $this->listener()->saveAvailableFrom('', $this->dc(1));
        self::assertSame('', $this->listener()->loadAvailableFrom(null, $this->dc(1)));
    }

    /**
     * Contao legt ein Feld mit inputType, ohne `sql` und ohne save_callback
     * still in die JSON-Spalte `jsonData` (VirtualFieldsMappingListener) —
     * dann stünde der Bestand doch im Produkt und würde mitkopiert. Die
     * Attribute werden vorher eingehängt (Priorität −16 vor −4096); fehlt
     * einer, passiert es.
     */
    public function testDieFelderOhneSpalteHabenIhrenSaveCallback(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(StockFieldsListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                $targets[] = $attribute->getArguments()['target'] ?? '';
            }
        }

        $fields = $GLOBALS['TL_DCA']['tl_consho_product']['fields'];

        foreach (['stockQuantity', 'stockAvailableFrom'] as $field) {
            self::assertArrayNotHasKey('sql', $fields[$field]);
            self::assertContains('fields.'.$field.'.save', $targets);

            // Review G, B1: the callback returns null — without this flag
            // DC_Table writes '' into a column tl_consho_product does not
            // have (DC_Table::save(), the condition before arrSubmit).
            self::assertTrue($fields[$field]['eval']['doNotSaveEmpty'] ?? false, $field);
        }

        self::assertArrayNotHasKey('inputType', $fields['stockOpened']);
        self::assertContains('fields.stockOpened.input_field', $targets);
    }

    /**
     * @param array<string, string> $post
     */
    private function listener(string $act = 'edit', array $post = []): StockFieldsListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => $act], $post));

        $security = $this->createMock(Security::class);

        return new StockFieldsListener($this->connection, $requests, $this->rows(), new ManualStock($this->rows(), new StockBooking($this->connection)), $security);
    }

    private function rows(): StockRows
    {
        return new StockRows($this->connection);
    }

    private function dc(int $id, string $inputName = ''): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => $id,
            'inputName' => $inputName,
            default => null,
        });

        return $dc;
    }

    private function palette(): string
    {
        return (string) $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'];
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DurabilityGuaranteeListener;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalArticleShippingData;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block GARAN (GA2, GA5): guarantee and label only on the main product of
 * goods; a duration of 1 or 2 years is not a guarantee within the meaning of
 * Z 12a. Type 1 physical, 2 digital. Product 1 goods, 2 its variant,
 * 3 digital.
 */
class DurabilityGuaranteeListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'productType' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'pid' => 0, 'productType' => 2]);

        require __DIR__.'/../../../contao/dca/tl_consho_product.php';
        require __DIR__.'/../../../contao/languages/de/tl_consho_product.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testAmHauptproduktEinerWareSichtbar(): void
    {
        $this->listener()->onLoad($this->dc(1));

        self::assertStringContainsString(',durabilityYears,durabilityLabel,durabilityNested', $this->palette());
    }

    /**
     * GA8: the nested label follows the full one in every rule — the same save
     * check hangs on both fields, a filled one stays visible.
     */
    public function testDasGeschachtelteLabelFolgtDemVollen(): void
    {
        $targets = array_map(
            static fn (\ReflectionAttribute $a): string => (string) ($a->getArguments()['target'] ?? ''),
            (new \ReflectionMethod(DurabilityGuaranteeListener::class, 'saveLabel'))->getAttributes(\Contao\CoreBundle\DependencyInjection\Attribute\AsCallback::class),
        );

        self::assertSame(['fields.durabilityLabel.save', 'fields.durabilityNested.save'], $targets);

        $this->connection->update('tl_consho_product', ['durabilityNested' => str_repeat('n', 16)], ['id' => 3]);
        $this->listener()->onLoad($this->dc(3));

        self::assertStringContainsString(',durabilityNested', $this->palette(), 'gefüllt an einer Nicht-Ware: sichtbar zum Leeren');
        self::assertStringNotContainsString('durabilityLabel', $this->palette());
    }

    public function testAnVarianteUndDigitalemProduktAusgeblendet(): void
    {
        $this->listener()->onLoad($this->dc(2));
        self::assertStringNotContainsString('durability', $this->palette(), 'die Variante erbt (GA2)');

        require __DIR__.'/../../../contao/dca/tl_consho_product.php';
        $this->listener()->onLoad($this->dc(3));
        self::assertStringNotContainsString('durability', $this->palette(), 'keine Ware (GA5)');
    }

    public function testEinGefuelltesFeldBleibtSichtbarDamitManEsLeerenKann(): void
    {
        $this->connection->update('tl_consho_product', ['durabilityYears' => 5], ['id' => 3]);
        $this->listener()->onLoad($this->dc(3));

        self::assertStringContainsString(',durabilityYears', $this->palette());
        self::assertStringNotContainsString('durabilityLabel', $this->palette());
    }

    public function testNurBeimBearbeiten(): void
    {
        $this->listener('show')->onLoad($this->dc(2));

        self::assertStringContainsString('durabilityYears', $this->palette());
    }

    public function testDreiJahreGehenZweiNicht(): void
    {
        self::assertSame('3', $this->listener()->saveYears('3', $this->dc(1)));
        self::assertSame('0', $this->listener()->saveYears('', $this->dc(1)));
        self::assertSame('0', $this->listener()->saveYears('0', $this->dc(3)), 'leeren geht auch an einer Nicht-Ware');

        $this->expectExceptionMessage('länger als zwei Jahre');
        $this->listener()->saveYears('2', $this->dc(1));
    }

    /**
     * GA9 (guidelines 3.1 vi): half years yes, with comma or point; other
     * decimals no. In the form the value stands the way the label writes it.
     */
    public function testHalbeJahreGehenAndereKommastellenNicht(): void
    {
        self::assertSame('2.5', $this->listener()->saveYears('2,5', $this->dc(1)));
        self::assertSame('4.5', $this->listener()->saveYears('4.5', $this->dc(1)));
        self::assertSame('5', $this->listener()->saveYears('5,0', $this->dc(1)));
        // Loaded in exactly the form the save delivers — otherwise DC_Table
        // takes every save for a change (part 2).
        foreach (['2.5', '5.0', '0.0'] as $stored) {
            $loaded = $this->listener()->loadYears($stored);
            self::assertSame($loaded, $this->listener()->saveYears($loaded, $this->dc(1)), $stored);
        }
        self::assertSame('2.5', $this->listener()->loadYears('2.5'));
        self::assertSame('5', $this->listener()->loadYears('5.0'));

        $this->expectExceptionMessage('ganzen oder halben Jahren');
        $this->listener()->saveYears('4,2', $this->dc(1));
    }

    public function testZweiKommaFuenfIstMehrAlsZwei(): void
    {
        $this->expectExceptionMessage('länger als zwei Jahre');
        $this->listener()->saveYears('1,5', $this->dc(1));
    }

    public function testKeineGarantieAnEinerNichtWare(): void
    {
        $this->expectExceptionMessage('nur für Waren');
        $this->listener()->saveYears('5', $this->dc(3));
    }

    public function testKeinLabelAnEinerNichtWare(): void
    {
        self::assertNull($this->listener()->saveLabel(null, $this->dc(3)));
        self::assertSame('x', $this->listener()->saveLabel('x', $this->dc(1)));

        $this->expectExceptionMessage('nur für Waren');
        $this->listener()->saveLabel(str_repeat('a', 16), $this->dc(3));
    }

    /**
     * GARAN: nothing takes effect on the variant — "Edit multiple" shows the
     * fields there anyway. Emptying works.
     */
    public function testAnDerVarianteAbgelehnt(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 1], ['id' => 2]);

        self::assertSame('0', $this->listener()->saveYears('', $this->dc(2)));
        self::assertNull($this->listener()->saveLabel(null, $this->dc(2)));

        $this->expectExceptionMessage('am Hauptprodukt');
        $this->listener()->saveYears('5', $this->dc(2));
    }

    public function testKeinLabelAusEinemGeschuetztenOrdner(): void
    {
        $files = $this->createMock(PublicFiles::class);
        $files->method('isPublic')->willReturnCallback(static fn (string $binary): bool => str_repeat('p', 16) === $binary);

        $requests = new RequestStack();
        $requests->push(new Request(['act' => 'edit']));
        $listener = new DurabilityGuaranteeListener($this->connection, $requests, new DbalArticleShippingData($this->connection), $files);

        self::assertSame(str_repeat('p', 16), $listener->saveLabel(str_repeat('p', 16), $this->dc(1)));

        $this->expectExceptionMessage('nicht öffentlich');
        $listener->saveLabel(str_repeat('x', 16), $this->dc(1));
    }

    private function palette(): string
    {
        return (string) $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'];
    }

    private function listener(string $act = 'edit'): DurabilityGuaranteeListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => $act]));

        return new DurabilityGuaranteeListener($this->connection, $requests, new DbalArticleShippingData($this->connection));
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

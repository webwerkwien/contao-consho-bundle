<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SaleModeListener;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalArticleShippingData;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-1, P2: Verkaufsart nur am Grundprodukt; die Variante behält ihre Laufzeit.
 * DL5: Der Abschnitt „Auslieferung und Gültigkeit" folgt der Art des Typs —
 * Typ 1 digital, 2 Dienstleistung, 3 körperlich.
 */
class SaleModeListenerTest extends TestCase
{
    private const DIGITAL = '{digital_legend:hide},digitalDelivery,digitalFile,keyImport,saleOnce,saleSubscription;';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_key');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'kind' => 'service']);
        $this->connection->insert('tl_consho_product_type', ['id' => 3, 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 4, 'kind' => 'voucher']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'productType' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);

        require __DIR__.'/../../../contao/dca/tl_consho_product.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testDasDigitaleGrundproduktZeigtAlles(): void
    {
        $this->listener('edit')->onLoad($this->dc(1));

        $palette = $this->palette();

        self::assertStringContainsString(self::DIGITAL, $palette);
        // Die Laufzeit steckt in der Subpalette des Einmalkaufs.
        self::assertStringNotContainsString('entitlementMonths', $palette);
        self::assertSame('entitlementMonths', $GLOBALS['TL_DCA']['tl_consho_product']['subpalettes']['saleOnce']);
    }

    public function testDieVarianteZeigtNurIhreLaufzeit(): void
    {
        $this->listener('edit')->onLoad($this->dc(2));

        $palette = $this->palette();

        self::assertStringNotContainsString('saleOnce', $palette);
        self::assertStringContainsString('keyImport,entitlementMonths;', $palette);
    }

    public function testEineDienstleistungHatNichtsAuszuliefern(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 2], ['id' => 1]);

        $this->listener('edit')->onLoad($this->dc(1));

        self::assertStringContainsString('{digital_legend:hide},saleOnce,saleSubscription;{publish_legend}', $this->palette());
    }

    public function testDieVarianteEinerDienstleistung(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 2], ['id' => 1]);

        $this->listener('edit')->onLoad($this->dc(2));

        self::assertStringContainsString('{digital_legend:hide},entitlementMonths;{publish_legend}', $this->palette());
    }

    public function testKoerperlicheWareOhneAbschnitt(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 3], ['id' => 1]);

        $this->listener('edit')->onLoad($this->dc(1));

        $palette = $this->palette();

        self::assertStringNotContainsString('digital_legend', $palette);
        self::assertStringContainsString('height;{publish_legend}', $palette);
    }

    /**
     * WG3: Der Gutschein zeigt Wert und Gültigkeit, aber nichts zum
     * Ausliefern und keine Verkaufsart; die Variante nur ihren Wert.
     */
    public function testDerGutscheinZeigtWertUndGueltigkeit(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 4], ['id' => 1]);

        $this->listener('edit')->onLoad($this->dc(1));
        $palette = $this->palette();
        self::assertStringContainsString('taxClass,voucherValue,voucherValidMonths,unit', $palette);
        self::assertStringNotContainsString('digital_legend', $palette);

        require __DIR__.'/../../../contao/dca/tl_consho_product.php';
        $this->listener('edit')->onLoad($this->dc(2));
        self::assertStringContainsString('taxClass,voucherValue,unit', $this->palette(), 'die Gültigkeit am Grundprodukt');
    }

    public function testNurDerGutscheinHatWertUndGueltigkeit(): void
    {
        foreach ([1, 2, 3] as $type) {
            require __DIR__.'/../../../contao/dca/tl_consho_product.php';
            $this->connection->update('tl_consho_product', ['productType' => $type], ['id' => 1]);

            $this->listener('edit')->onLoad($this->dc(1));

            self::assertStringNotContainsString('voucherValue', $this->palette(), 'Typ '.$type);
        }
    }

    public function testOhneTypKoerperlich(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 0], ['id' => 1]);

        $this->listener('edit')->onLoad($this->dc(2));

        self::assertStringNotContainsString('digital_legend', $this->palette());
    }

    /**
     * Abnahme DL, Fund 1: Was die Art nicht verwendet, aber belegt ist, bleibt
     * sichtbar und sagt es — sonst verlangt der Befund etwas Unerreichbares.
     */
    public function testBelegteFelderBleibenBeiDerDienstleistungSichtbar(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 2, 'digitalDelivery' => 'file', 'digitalFile' => str_repeat('a', 16)], ['id' => 1]);
        $this->connection->insert('tl_consho_key', ['pid' => 1, 'code' => 'ABC']);
        $GLOBALS['TL_LANG']['tl_consho_product']['kindUnused'] = 'unbenutzt: %s.';
        $GLOBALS['TL_LANG']['tl_consho_product']['kindUnusedKeys'] = 'Schlüssel unbenutzt: %s.';
        $GLOBALS['TL_LANG']['tl_consho_product']['digitalFile'] = ['Datei', 'Hilfe'];
        $GLOBALS['TL_LANG']['CONSHO']['kindMismatch']['kinds']['service'] = 'eine Dienstleistung';

        $this->listener('edit')->onLoad($this->dc(1));

        self::assertStringContainsString('{digital_legend:hide},digitalDelivery,digitalFile,keyImport,saleOnce,saleSubscription;', $this->palette());
        self::assertSame(['Datei', 'unbenutzt: eine Dienstleistung. Hilfe'], $GLOBALS['TL_LANG']['tl_consho_product']['digitalFile']);
        self::assertStringStartsWith('Schlüssel unbenutzt', $GLOBALS['TL_LANG']['tl_consho_product']['keyImport'][1]);
    }

    /**
     * Spec Wertgutscheine, offene Punkte: Gewicht und Maße standen auch bei
     * Gutschein, E-Book und Dienstleistung in der Maske. Nur Ware hat sie.
     */
    public function testVersandfelderNurBeiKoerperlicherWare(): void
    {
        foreach ([1 => false, 2 => false, 3 => true, 4 => false] as $type => $shown) {
            require __DIR__.'/../../../contao/dca/tl_consho_product.php';
            $this->connection->update('tl_consho_product', ['productType' => $type], ['id' => 1]);

            $this->listener('edit')->onLoad($this->dc(1));

            self::assertSame($shown, str_contains($this->palette(), 'shipping_legend'), 'Typ '.$type);
            self::assertSame($shown, str_contains($this->palette(), 'shippingWeight'), 'Typ '.$type);
        }
    }

    /** Wie bei den digitalen Feldern: Belegtes bleibt sichtbar und sagt es. */
    public function testBelegtesGewichtBleibtBeimGutscheinSichtbar(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 4, 'weight' => '0.2500'], ['id' => 1]);
        $GLOBALS['TL_LANG']['tl_consho_product']['kindUnused'] = 'unbenutzt: %s.';
        $GLOBALS['TL_LANG']['tl_consho_product']['weight'] = ['Gewicht', 'Hilfe'];
        $GLOBALS['TL_LANG']['CONSHO']['kindMismatch']['kinds']['voucher'] = 'ein Gutschein';

        $this->listener('edit')->onLoad($this->dc(1));

        self::assertStringContainsString('{shipping_legend},weight;', $this->palette());
        self::assertSame(['Gewicht', 'unbenutzt: ein Gutschein. Hilfe'], $GLOBALS['TL_LANG']['tl_consho_product']['weight']);
    }

    public function testAboAnKoerperlicherWareBleibtSichtbar(): void
    {
        $this->connection->update('tl_consho_product', ['productType' => 3, 'saleSubscription' => 1], ['id' => 1]);

        $this->listener('edit')->onLoad($this->dc(1));

        self::assertStringContainsString('{digital_legend},saleSubscription;{publish_legend}', $this->palette());
    }

    public function testInDerListeAendertSichNichts(): void
    {
        // In der Liste ist die id die des Elternsatzes oder keine.
        $before = $this->palette();

        $this->listener('')->onLoad($this->dc(2));

        self::assertSame($before, $this->palette());
    }

    public function testDerTypSchicktDieMaskeAb(): void
    {
        // Die Felder folgen der Art sofort, nicht erst nach dem Speichern.
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_product']['fields']['productType']['eval']['submitOnChange']);
    }

    private function palette(): string
    {
        return $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'];
    }

    private function listener(string $act): SaleModeListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => $act]));

        return new SaleModeListener($this->connection, $requests, new DbalArticleShippingData($this->connection));
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

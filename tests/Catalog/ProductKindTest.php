<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * DL1, DL5: die Art am Produkttyp und was aus ihr folgt.
 */
class ProductKindTest extends TestCase
{
    public function testWasAusDerArtFolgt(): void
    {
        self::assertSame(
            [
                'physical' => [true, false, ''],
                'digital' => [false, true, 'content'],
                'service' => [false, true, 'service'],
                // WG3: kein Versand, kein Abo, keine Zustimmung zum vorzeitigen Beginn.
                'voucher' => [false, false, ''],
            ],
            array_combine(
                array_map(static fn (ProductKind $kind): string => $kind->value, ProductKind::cases()),
                array_map(static fn (ProductKind $kind): array => [$kind->isPhysical(), $kind->allowsSubscription(), $kind->withdrawalKind()], ProductKind::cases()),
            ),
        );
    }

    public function testOhneOderMitUnbekanntemWertKoerperlich(): void
    {
        self::assertSame(ProductKind::Physical, ProductKind::stored(''));
        self::assertSame(ProductKind::Physical, ProductKind::stored(null));
        self::assertSame(ProductKind::Physical, ProductKind::stored('content'));
        self::assertSame(ProductKind::Service, ProductKind::stored('service'));
    }

    public function testDieDcaBietetGenauDieArtenAn(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_product_type']['kinds'] = [];
        require __DIR__.'/../../contao/dca/tl_consho_product_type.php';
        $fields = $GLOBALS['TL_DCA']['tl_consho_product_type']['fields'];

        self::assertSame(['physical', 'digital', 'service', 'voucher'], $fields['kind']['options']);
        self::assertSame('physical', $fields['kind']['sql']['default']);
        self::assertArrayNotHasKey('isPhysical', $fields);
        self::assertStringContainsString(',kind;', $GLOBALS['TL_DCA']['tl_consho_product_type']['palettes']['default']);

        unset($GLOBALS['TL_DCA']['tl_consho_product_type'], $GLOBALS['TL_LANG']['tl_consho_product_type']);
    }

    /**
     * DL1, DL7: Bestellzeile und Abo frieren die Art ein; die alten Spalten
     * gibt es nicht mehr.
     */
    public function testBestellzeileUndAboTragenDieArt(): void
    {
        foreach (['tl_consho_order_item', 'tl_consho_subscription'] as $table) {
            require __DIR__.'/../../contao/dca/'.$table.'.php';
            $fields = $GLOBALS['TL_DCA'][$table]['fields'];

            self::assertSame(['physical', 'digital', 'service', 'voucher'], $fields['kind']['options'], $table);
            self::assertArrayNotHasKey('isPhysical', $fields, $table);
            self::assertArrayNotHasKey('withdrawalKind', $fields, $table);

            foreach (['de', 'en'] as $language) {
                require __DIR__.'/../../contao/languages/'.$language.'/'.$table.'.php';
                self::assertSame($fields['kind']['options'], array_keys($GLOBALS['TL_LANG'][$table]['kinds']), $table.' '.$language);
            }

            unset($GLOBALS['TL_DCA'][$table], $GLOBALS['TL_LANG'][$table]);
        }
    }
}

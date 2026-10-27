<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

class ShopMethodsDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_shop.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testVersandLinksZahlungRechtsSortierbarAngehakt(): void
    {
        // C2: like the stylesheets in the page layout — checkboxes, dragging, topmost = default.
        $dca = $GLOBALS['TL_DCA']['tl_consho_shop'];

        self::assertStringContainsString('{methods_legend},shippingMethods,paymentMethods', $dca['palettes']['default']);

        // Checked exactly, not just for 'w50': 'w50 clr' twice would throw
        // both fields onto a new line instead of placing them side by side.
        self::assertSame('w50 clr', $dca['fields']['shippingMethods']['eval']['tl_class']);
        self::assertSame('w50', $dca['fields']['paymentMethods']['eval']['tl_class']);

        foreach (['shippingMethods', 'paymentMethods'] as $field) {
            self::assertSame('checkboxWizard', $dca['fields'][$field]['inputType'], $field);
            self::assertTrue($dca['fields'][$field]['eval']['multiple'], $field);
            self::assertSame('blob', $dca['fields'][$field]['sql']['type'], $field);
        }
    }
}

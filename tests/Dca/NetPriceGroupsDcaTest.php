<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * The customer groups with net prices on the shop (C20, C-4).
 *
 * ⚠️ Why this test exists: a field that is missing from the palette or
 * whose legend has a typo is invisible in the backend — and not one of the
 * other tests would come close, because they all build the classes directly
 * and never a form. The same kind of construction as a bug that once took
 * the backend down.
 */
class NetPriceGroupsDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_shop.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDasFeldIstEineMehrfachauswahlUeberDieMitgliedergruppen(): void
    {
        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['netPriceGroups'];

        self::assertSame('select', $field['inputType']);
        self::assertTrue($field['eval']['multiple']);
        // The foreign key fills the options; without it the form would stand
        // empty, and without an error message.
        self::assertSame('tl_member_group.name', $field['foreignKey']);
        self::assertSame('blob', $field['sql']['type']);
    }

    public function testDasFeldIstKeinPflichtfeld(): void
    {
        // The normal case is the shop without net groups. A mandatory field
        // would force every operator to answer a question that does not
        // concern them.
        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['netPriceGroups'];

        self::assertArrayNotHasKey('mandatory', $field['eval']);
    }

    public function testDasFeldStehtInDerPaletteUnterEinerEigenenLegende(): void
    {
        $palette = $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default'];

        // Since D-4 the customers' base group comes before it (K5).
        self::assertStringContainsString('{customergroup_legend},customerGroup,netPriceGroups', $palette);
    }

    public function testDieLegendeUndDasFeldSindInBeidenSprachenBenannt(): void
    {
        // A legend without text appears in the backend as its own
        // key — "customergroup_legend" above the select.
        foreach (['de', 'en'] as $language) {
            $labels = $this->labels($language);

            self::assertArrayHasKey('customergroup_legend', $labels, $language);
            self::assertArrayHasKey('netPriceGroups', $labels, $language);
            self::assertCount(2, $labels['netPriceGroups'], $language);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(string $language): array
    {
        $GLOBALS['TL_LANG'] = [];

        require __DIR__.'/../../contao/languages/'.$language.'/tl_consho_shop.php';

        /** @var array<string, mixed> $labels */
        $labels = $GLOBALS['TL_LANG']['tl_consho_shop'];

        unset($GLOBALS['TL_LANG']);

        return $labels;
    }
}

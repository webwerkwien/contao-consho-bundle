<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\TranslatableFields;

/**
 * Pins down the DCA settings DC_Table depends on when deleting, copying
 * and cleaning up.
 *
 * The behaviour itself cannot be tested here — that would need DC_Table
 * with a database and a backend user. So what is checked is the switch it
 * hangs on in the core (DC_Table::deleteChildren, copyChildren,
 * reviseTable). Whether it takes effect in the browser has to be checked
 * in a real backend.
 */
class TranslationDcaTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['BE_MOD'], $GLOBALS['TL_MODELS']);
    }

    public function testChildRowsAreAddressedByParentTableAndId(): void
    {
        // Without dynamicPtable Contao filters child rows by pid only: whoever
        // deletes product 5 also deletes the translations of manufacturer 5.
        self::assertTrue($this->dca('tl_consho_translation')['config']['dynamicPtable'] ?? false);
    }

    public function testCopyingAParentDoesNotCopyTheTranslatedAlias(): void
    {
        // copyChildren takes over every column, the alias too — the copy then
        // violated UNIQUE (ptable, language, alias). doNotCopy sets it to the
        // column's empty value, and that is NULL here, not ''.
        $alias = $this->dca('tl_consho_translation')['fields']['alias'];

        self::assertTrue($alias['eval']['doNotCopy'] ?? false);
        self::assertFalse($alias['sql']['notnull']);
    }

    public function testEveryTranslatedTableDeclaresTheTranslationsAsItsChildren(): void
    {
        // Only as a ctable do the translations move to tl_undo on deletion,
        // instead of being left behind orphaned.
        foreach (TranslatableFields::tables() as $table) {
            self::assertContains('tl_consho_translation', $this->dca($table)['config']['ctable'] ?? [], $table);
        }
    }

    /**
     * 🔴 In C-8, `invoiceFooter` stood in TranslatableFields, but
     * tl_consho_translation has a column PER FIELD — and that one did not
     * exist. The whole suite stayed green; on a real installation the first
     * save of a translation would have failed with "Unknown column".
     */
    public function testEveryTranslatableFieldHasAColumnInTheTranslationTable(): void
    {
        $columns = $this->dca('tl_consho_translation')['fields'];

        foreach (TranslatableFields::tables() as $table) {
            foreach (TranslatableFields::of($table) as $field) {
                self::assertArrayHasKey('sql', $columns[$field] ?? [], "$table.$field");
            }
        }
    }

    public function testEveryTranslatedTableIsVersioned(): void
    {
        // Without enableVersioning Versions::create() returns immediately — the
        // oncreate callback that puts the translations into the version would
        // never run.
        foreach (TranslatableFields::tables() as $table) {
            self::assertTrue($this->dca($table)['config']['enableVersioning'] ?? false, $table);
        }
    }

    public function testExistingChildTablesStayFirst(): void
    {
        // Contao's standard operation "child elements" and the breadcrumb
        // take ctable[0].
        self::assertSame('tl_consho_price', $this->dca('tl_consho_product')['config']['ctable'][0]);
        self::assertSame('tl_consho_property_option', $this->dca('tl_consho_property_group')['config']['ctable'][0]);
        self::assertSame('tl_consho_shipping_price', $this->dca('tl_consho_shipping_method')['config']['ctable'][0]);
    }

    public function testEveryModuleThatReachesATranslatedTableMayReachTheTranslations(): void
    {
        // Otherwise deleting aborts with "Table … is not allowed in module …".
        require __DIR__.'/../../contao/config/config.php';

        foreach (['consho_data' => ['consho_product', 'consho_manufacturer'], 'consho_setup' => ['consho_property', 'consho_product_type', 'consho_shipping', 'consho_payment']] as $group => $modules) {
            foreach ($modules as $module) {
                self::assertContains('tl_consho_translation', $GLOBALS['BE_MOD'][$group][$module]['tables'], $module);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dca(string $table): array
    {
        require __DIR__.'/../../contao/dca/'.$table.'.php';

        return $GLOBALS['TL_DCA'][$table];
    }
}

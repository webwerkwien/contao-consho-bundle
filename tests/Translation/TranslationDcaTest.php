<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\TranslatableFields;

/**
 * Hält die DCA-Einstellungen fest, von denen DC_Table beim Löschen, Kopieren
 * und Aufräumen abhängt.
 *
 * Das Verhalten selbst lässt sich hier nicht prüfen — dafür bräuchte es
 * DC_Table mit Datenbank und Backend-Benutzer. Geprüft wird deshalb der
 * Schalter, an dem es im Core hängt (DC_Table::deleteChildren, copyChildren,
 * reviseTable). Ob er im Browser wirkt, gehört in die Abnahme auf dem Testserver.
 */
class TranslationDcaTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['BE_MOD'], $GLOBALS['TL_MODELS']);
    }

    public function testChildRowsAreAddressedByParentTableAndId(): void
    {
        // Ohne dynamicPtable filtert Contao Kindzeilen nur nach pid: Wer
        // Produkt 5 löscht, löscht auch die Übersetzungen von Hersteller 5.
        self::assertTrue($this->dca('tl_consho_translation')['config']['dynamicPtable'] ?? false);
    }

    public function testCopyingAParentDoesNotCopyTheTranslatedAlias(): void
    {
        // copyChildren übernimmt jede Spalte, auch den Alias — die Kopie
        // verletzte dann UNIQUE (ptable, language, alias). doNotCopy setzt ihn
        // auf den Leerwert der Spalte, und der ist hier NULL, nicht ''.
        $alias = $this->dca('tl_consho_translation')['fields']['alias'];

        self::assertTrue($alias['eval']['doNotCopy'] ?? false);
        self::assertFalse($alias['sql']['notnull']);
    }

    public function testEveryTranslatedTableDeclaresTheTranslationsAsItsChildren(): void
    {
        // Nur als ctable wandern die Übersetzungen beim Löschen nach tl_undo,
        // statt verwaist liegenzubleiben.
        foreach (TranslatableFields::tables() as $table) {
            self::assertContains('tl_consho_translation', $this->dca($table)['config']['ctable'] ?? [], $table);
        }
    }

    /**
     * 🔴 Gefunden in C-8: `invoiceFooter` stand in TranslatableFields, aber
     * tl_consho_translation hat eine Spalte JE FELD — und die gab es nicht.
     * Die ganze Suite blieb grün; auf c5 wäre das erste Speichern einer
     * Übersetzung an „Unknown column" gescheitert.
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
        // Ohne enableVersioning kehrt Versions::create() sofort zurück — der
        // oncreate-Callback, der die Übersetzungen in die Version legt, liefe
        // nie.
        foreach (TranslatableFields::tables() as $table) {
            self::assertTrue($this->dca($table)['config']['enableVersioning'] ?? false, $table);
        }
    }

    public function testExistingChildTablesStayFirst(): void
    {
        // Contaos Standard-Operation „Kindelemente" und der Brotkrumenpfad
        // nehmen ctable[0].
        self::assertSame('tl_consho_price', $this->dca('tl_consho_product')['config']['ctable'][0]);
        self::assertSame('tl_consho_property_option', $this->dca('tl_consho_property_group')['config']['ctable'][0]);
        self::assertSame('tl_consho_shipping_price', $this->dca('tl_consho_shipping_method')['config']['ctable'][0]);
    }

    public function testEveryModuleThatReachesATranslatedTableMayReachTheTranslations(): void
    {
        // Sonst bricht das Löschen mit „Table … is not allowed in module …" ab.
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

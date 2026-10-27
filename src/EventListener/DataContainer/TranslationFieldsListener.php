<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\DC_Table;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslatableFields;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationWriter;

/**
 * One section per translation language in the mask of every translated
 * entity — Block M, section 7.
 *
 * The fields (`title__en`, …) are created when the DCA is loaded and exist
 * nowhere in the main table. Their names are also the keys of the version
 * snapshot in M-5; do not rename them here.
 *
 * ── How a field without a column survives DC_Table::save() ────────────────
 *
 * The save callback always runs, and the comparison with the loaded value
 * comes after it. That loaded value is whatever load() returned — the stored
 * translation — so returning '' alone would still differ from it and put the
 * field name into the UPDATE, an SQL error. `doNotSaveEmpty` is the part that
 * keeps it out: an empty value is then not collected at all. Same mechanism
 * as `shops` in ProductShopsListener.
 *
 * ── Why the block is written in onsubmit, not field by field ──────────────
 *
 * TranslationStorage::write() replaces a whole language block, and whether a
 * block is empty — and therefore deleted — can only be decided with all of
 * its fields in hand. save() collects, store() writes.
 *
 * ── Callbacks as [$this, 'load'] ──────────────────────────────────────────
 *
 * System::importStatic() accepts an object and caches it under its class
 * name, which is also the key the onload call through the service id used.
 * Both therefore reach this very instance, and the buffer below is shared
 * between save() and store() without a global.
 */
class TranslationFieldsListener
{
    /**
     * Keys that describe the main table's column rather than the input.
     *
     * `sql` above all — contao:migrate would otherwise create `title__en` in
     * tl_consho_product. The callbacks go because they were registered for
     * the original field: the alias one, for instance, would generate a
     * main-table alias from the main-table title.
     */
    private const COLUMN_KEYS = [
        'sql', 'search', 'sorting', 'filter', 'flag', 'length', 'relation', 'foreignKey',
        'load_callback', 'save_callback', 'default', 'toggle', 'reverseToggle',
    ];

    /**
     * `mandatory` would make a product unsaveable until every language is
     * finished (specification 7.3). `unique` checks the main table, where the
     * column does not exist.
     */
    private const COLUMN_EVAL = ['mandatory', 'unique', 'alwaysSave', 'fallback', 'doNotCopy'];

    /**
     * Submitted values per table, record, language and field — keyed by
     * record because "edit multiple" submits several records in one request.
     *
     * @var array<string, array<int, array<string, array<string, string>>>>
     */
    private array $buffer = [];

    /**
     * The rules of a language block (CW-2), shared with console commands.
     * Built here from the same services rather than injected, so the mask's
     * tests keep constructing the listener as before — they are the proof
     * that the mask did not change.
     */
    private readonly TranslationWriter $writer;

    public function __construct(
        private readonly ShopLanguages $languages,
        private readonly TranslationStorage $storage,
        AliasFactory $aliases,
        private readonly Security $security,
        RedirectRecorder $recorder,
    ) {
        $this->writer = new TranslationWriter($storage, $aliases, $recorder, $languages);
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_property_group', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_payment_method', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_shop', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_custom_field', target: 'config.onload')]
    public function addFields(DataContainer|null $dc = null): void
    {
        if (null === $dc) {
            return;
        }

        $table = (string) $dc->table;
        $fields = $this->fieldsInMaskOrder($table);

        // Guard against a second DC_Table in the same request: the palette
        // would otherwise carry every section twice.
        if ([] === $fields || isset($GLOBALS['TL_DCA'][$table]['config']['conshoTranslationLanguages'])) {
            return;
        }

        $languages = $this->languagesFor($table, $dc);

        // A monolingual shop sees exactly the mask it saw before — the
        // touchstone of the whole specification (section 2).
        if ([] === $languages) {
            return;
        }

        foreach ($languages as $language) {
            $names = [];

            foreach ($fields as $field) {
                $name = TranslatableFields::fieldName($field, $language);
                $GLOBALS['TL_DCA'][$table]['fields'][$name] = $this->definition($table, $field, $language);
                $names[] = $name;
            }

            // Legend keys may not contain a hyphen as reliably as field names
            // do; de-AT becomes de_AT here only, never in the field name.
            $legend = 'consho_translation_'.str_replace('-', '_', $language).'_legend';
            $GLOBALS['TL_LANG'][$table][$legend] = \sprintf($GLOBALS['TL_LANG']['CONSHO']['translationLegend'] ?? '%s', $language);

            foreach ($GLOBALS['TL_DCA'][$table]['palettes'] ?? [] as $key => $palette) {
                if ('__selector__' === $key || !\is_string($palette)) {
                    continue;
                }

                $GLOBALS['TL_DCA'][$table]['palettes'][$key] = $palette.';{'.$legend.':hide},'.implode(',', $names);
            }
        }

        // For the tab strip (M-4, task 5).
        $GLOBALS['TL_DCA'][$table]['config']['conshoTranslationLanguages'] = $languages;
    }

    public function load(mixed $value, DataContainer $dc): string
    {
        $target = $this->target($dc);

        if (null === $target) {
            return \is_scalar($value) ? (string) $value : '';
        }

        [$table, $field, $language] = $target;

        return $this->storage->read($table, (int) $dc->id, $language, $field);
    }

    /**
     * Collects the value and returns '' so that nothing reaches the main
     * table — see the class note on `doNotSaveEmpty`.
     *
     * The alias is checked HERE and not in store(): an exception thrown from
     * a save callback is shown at the field, keeps the typed values in the
     * form and stops DC_Table before onsubmit. Thrown from onsubmit it would
     * be an error page, and anything else there — a message plus a redirect —
     * would silently discard what the editor typed.
     *
     * Whether an empty alias is allowed depends on the whole block. That works
     * because the alias is the LAST field of each section (see
     * fieldsInMaskOrder()): by the time its save callback runs, every other
     * submitted field of the block is in the buffer.
     */
    public function save(mixed $value, DataContainer $dc): string
    {
        $target = $this->target($dc);

        if (null === $target) {
            return \is_scalar($value) ? (string) $value : '';
        }

        [$table, $field, $language] = $target;

        $id = (int) $dc->id;
        $value = \is_scalar($value) ? (string) $value : '';

        if ('alias' === $field) {
            $value = trim($value);
            $this->writer->checkAlias($table, $id, $language, $value, $this->buffer[$table][$id][$language] ?? []);
        }

        $this->buffer[$table][$id][$language][$field] = $value;

        return '';
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_property_group', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_payment_method', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_shop', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_consho_custom_field', target: 'config.onsubmit')]
    public function store(DataContainer $dc): void
    {
        $table = (string) $dc->table;
        $id = (int) $dc->id;

        $blocks = $this->buffer[$table][$id] ?? [];
        unset($this->buffer[$table][$id]);

        if ($id < 1) {
            return;
        }

        $changed = false;

        foreach ($blocks as $language => $values) {
            // store() first: every block is written, also after a changed one.
            $changed = $this->writer->store($table, $id, (string) $language, $values) || $changed;
        }

        /*
         * Without this a change to translations alone would be in no version.
         * The translated fields never reach the parent's UPDATE, so if nothing
         * else changed DC_Table::submit() runs no UPDATE and leaves
         * createNewVersion false. Contao reads the flag after the onsubmit
         * callbacks, and the version it then creates picks up the translations
         * just written (TranslationVersionListener).
         *
         * The flag is DC_Table's (and DC_Folder's), not DataContainer's; these
         * tables are DC_Table, and another driver has no flag to set.
         */
        if ($changed && $dc instanceof DC_Table) {
            $dc->createNewVersion = true;
        }
    }

    /**
     * The field definition for one language: the original's input, none of
     * its column.
     *
     * @return array<string, mixed>
     */
    private function definition(string $table, string $field, string $language): array
    {
        $definition = $GLOBALS['TL_DCA'][$table]['fields'][$field];

        foreach (self::COLUMN_KEYS as $key) {
            unset($definition[$key]);
        }

        foreach (self::COLUMN_EVAL as $key) {
            unset($definition['eval'][$key]);
        }

        $label = $GLOBALS['TL_LANG'][$table][$field] ?? $definition['label'] ?? null;
        $label = \is_array($label) ? $label : [\is_string($label) ? $label : $field, ''];

        // Unset before assigning: a DCA label is often a reference into
        // TL_LANG, and a reference survives the array copy above. Assigning
        // through it would rename the ORIGINAL field to "Bezeichnung (en)".
        unset($definition['label']);
        $definition['label'] = [($label[0] ?? $field).' ('.$language.')', $label[1] ?? ''];

        $definition['eval']['doNotSaveEmpty'] = true;

        /*
         * Contao counts a field with an input type and no `exclude` key as
         * excluded (DataContainer::isFieldExcluded). A generated field cannot
         * be granted in the user group — the group form never sees it — so it
         * inherits the permission of the field it translates.
         */
        $excluded = (bool) ($definition['exclude'] ?? isset($definition['inputType']));
        $definition['exclude'] = $excluded && !$this->security->isGranted(
            ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE,
            $table.'::'.$field,
        );

        $definition['load_callback'] = [[$this, 'load']];
        $definition['save_callback'] = [[$this, 'save']];

        return $definition;
    }

    /**
     * The translatable fields of the table, alias moved to the end.
     *
     * Not cosmetics: the conditional alias requirement is checked in the
     * alias's own save callback and needs the rest of the block to have been
     * submitted before it. DC_Table processes fields in palette order.
     *
     * @return list<string>
     */
    private function fieldsInMaskOrder(string $table): array
    {
        // Only fields with an input: a translatable column without one (a
        // product's customFieldValues) gets its fields elsewhere
        // (CustomFieldsListener), one per custom field.
        $fields = array_values(array_filter(
            TranslatableFields::of($table),
            static fn (string $field): bool => isset($GLOBALS['TL_DCA'][$table]['fields'][$field]['inputType']),
        ));

        if (!\in_array('alias', $fields, true)) {
            return $fields;
        }

        return [...array_values(array_diff($fields, ['alias'])), 'alias'];
    }

    /**
     * The record's languages (TranslationWriter::languagesOf()).
     *
     * @return list<string>
     */
    private function languagesFor(string $table, DataContainer $dc): array
    {
        $id = (int) $dc->id;

        /*
         * No id means "edit multiple" or "override all": DC_Table only sets the
         * id inside its record loop, after the onload callbacks have run. The
         * selection may span several shops, so offer every translation
         * language. A section for a language a record does not have stays
         * empty, and an empty block writes nothing.
         */
        if ($id < 1) {
            return $this->languages->allTranslated();
        }

        // The parent from the record, not a query: the mask has it loaded.
        return $this->writer->languagesOf($table, $id, (int) ($dc->getCurrentRecord()['pid'] ?? 0));
    }

    /**
     * @return array{string, string, string}|null table, field, language
     */
    private function target(DataContainer $dc): array|null
    {
        $table = (string) $dc->table;
        $parts = TranslatableFields::split((string) $dc->field);

        if ([] === $parts || !\in_array($parts[0], TranslatableFields::of($table), true)) {
            return null;
        }

        return [$table, $parts[0], $parts[1]];
    }
}

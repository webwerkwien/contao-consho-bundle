<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomField;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldValues;
use Webwerkwien\ContaoConshoBundle\Translation\CustomFieldTranslations;

/**
 * The custom fields of a product in its mask (block "Zusatzfeld-Werte",
 * ZW-2): one field per custom field of the product type, `cf_<alias>`, in a
 * section of its own — the product type decides which (block A 3.1).
 *
 * The fields have no column, like the translation fields
 * (TranslationFieldsListener, same mechanism): save() checks and collects the
 * input — a wrong number is shown at the field, the typed values stay — and
 * returns '' with `doNotSaveEmpty`, so nothing reaches the UPDATE. store()
 * writes the JSON column in onsubmit and asks DC_Table for a version.
 *
 * A variant shows its own values; an empty field inherits the main
 * product's, which the help text names (Z2). Yes/no is a select with an
 * empty choice for the same reason: a checkbox could not say "inherit".
 *
 * Text fields get one more field per translation language of the product,
 * `cf_<alias>__<language>`, in a section "Zusatzfelder (fr)" (ZW-3, Z1);
 * left empty, the default language's text applies. Number, date and yes/no
 * read the same in every language.
 *
 * Only for one record: "edit multiple" spans products of different types.
 */
class CustomFieldsListener
{
    private const PREFIX = 'cf_';

    private const SEPARATOR = '__';

    /**
     * Submitted values per record, language ('' = the product's own) and
     * alias.
     *
     * @var array<int, array<string, array<string, string>>>
     */
    private array $buffer = [];

    /**
     * The fields of the record the mask was built for.
     *
     * @var array<int, list<CustomField>>
     */
    private array $fields = [];

    public function __construct(
        private readonly CustomFieldValues $values,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly CustomFieldTranslations $translations,
    ) {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onload')]
    public function addFields(DataContainer|null $dc = null): void
    {
        $id = null === $dc ? 0 : (int) $dc->id;

        if (null === $dc || $id < 1 || isset($GLOBALS['TL_DCA']['tl_consho_product']['config']['conshoCustomFields'])) {
            return;
        }

        $fields = $this->values->fieldsOf($id);
        $GLOBALS['TL_DCA']['tl_consho_product']['config']['conshoCustomFields'] = true;

        if ([] === $fields) {
            return;
        }

        $this->fields[$id] = $fields;
        $pid = (int) ($dc->getCurrentRecord()['pid'] ?? 0);
        $inherited = $pid > 0 ? $this->values->ownValues($pid) : null;
        $lang = $GLOBALS['TL_LANG']['tl_consho_product'] ?? [];

        // The generated fields cannot be granted in the user group; they take
        // the permission of the column (as translation fields take theirs).
        $excluded = !$this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, 'tl_consho_product::customFieldValues');
        $names = [];

        foreach ($fields as $field) {
            $name = self::PREFIX.$field->alias;
            $help = null === $inherited ? '' : (isset($inherited[$field->alias])
                ? \sprintf($lang['customFieldInherited'] ?? '%s', self::shown($field, $inherited[$field->alias], $lang))
                : ($lang['customFieldInheritedNone'] ?? ''));

            $GLOBALS['TL_DCA']['tl_consho_product']['fields'][$name] = [
                'label' => [$field->title, $help],
                'exclude' => $excluded,
                'load_callback' => [[$this, 'load']],
                'save_callback' => [[$this, 'save']],
                ...self::input($field, $lang),
            ];
            $names[] = $name;
        }

        $sections = ';{custom_legend},'.implode(',', $names);
        $texts = array_values(array_filter($fields, static fn (CustomField $field): bool => CustomField::TEXT === $field->type));

        foreach ([] === $texts ? [] : $this->translations->languagesOf($id) as $language) {
            $translated = [];

            foreach ($texts as $field) {
                $name = self::PREFIX.$field->alias.self::SEPARATOR.$language;
                $GLOBALS['TL_DCA']['tl_consho_product']['fields'][$name] = [
                    'label' => [$field->title.' ('.$language.')', ''],
                    'exclude' => $excluded,
                    'load_callback' => [[$this, 'load']],
                    'save_callback' => [[$this, 'save']],
                    ...self::input($field, $lang),
                ];
                $translated[] = $name;
            }

            // A hyphen in a legend key is not reliable; de-AT becomes de_AT here only.
            $legend = 'custom_'.str_replace('-', '_', $language).'_legend';
            $GLOBALS['TL_LANG']['tl_consho_product'][$legend] = ($lang['custom_legend'] ?? 'Custom fields').' ('.$language.')';
            $sections .= ';{'.$legend.':hide},'.implode(',', $translated);
        }

        foreach ($GLOBALS['TL_DCA']['tl_consho_product']['palettes'] ?? [] as $key => $palette) {
            // `__selector__` is a list, not a palette.
            if (\is_string($palette)) {
                $GLOBALS['TL_DCA']['tl_consho_product']['palettes'][$key] = $palette.$sections;
            }
        }
    }

    public function load(mixed $value, DataContainer $dc): mixed
    {
        [$field, $language] = $this->target($dc);

        if (null === $field) {
            return $value;
        }

        if ('' !== $language) {
            return $this->translations->read((int) $dc->id, $language)[$field->alias] ?? '';
        }

        $stored = $this->values->ownValues((int) $dc->id)[$field->alias] ?? '';

        // The date widget shows a timestamp in the back end's format.
        if (CustomField::DATE === $field->type && '' !== $stored) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $stored);

            return false === $date ? '' : $date->getTimestamp();
        }

        return $stored;
    }

    /**
     * @throws \InvalidArgumentException shown by Contao at the field
     */
    public function save(mixed $value, DataContainer $dc): string
    {
        [$field, $language] = $this->target($dc);

        if (null === $field) {
            return \is_scalar($value) ? (string) $value : '';
        }

        $raw = \is_scalar($value) ? trim((string) $value) : '';

        // The date widget hands over a timestamp (rgxp date).
        if (CustomField::DATE === $field->type && '' !== $raw && ctype_digit(ltrim($raw, '-'))) {
            $raw = (new \DateTimeImmutable('@'.$raw))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
        }

        CustomFieldValues::normalize($field, $raw);
        $this->buffer[(int) $dc->id][$language][$field->alias] = $raw;

        return '';
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit')]
    public function store(DataContainer $dc): void
    {
        $id = (int) $dc->id;
        $blocks = $this->buffer[$id] ?? [];
        unset($this->buffer[$id]);

        if ($id < 1 || [] === $blocks) {
            return;
        }

        $changed = false;
        $own = $blocks[''] ?? [];
        unset($blocks['']);

        if ([] !== $own) {
            $before = $this->values->ownValues($id);
            $after = $this->values->merge($before, $this->fields[$id] ?? $this->values->fieldsOf($id), $own);

            if ($after !== $before) {
                $this->connection->update('tl_consho_product', ['customFieldValues' => $this->values->column($after)], ['id' => $id]);
                $changed = true;
            }
        }

        foreach ($blocks as $language => $input) {
            $changed = $this->translations->set($id, (string) $language, $input) || $changed;
        }

        // As for translations: the column changed after DC_Table's UPDATE,
        // and without the flag no version would hold the new values.
        if ($changed && $dc instanceof DC_Table) {
            $dc->createNewVersion = true;
        }
    }

    /**
     * @param array<string, mixed> $lang
     *
     * @return array<string, mixed>
     */
    private static function input(CustomField $field, array $lang): array
    {
        return match ($field->type) {
            CustomField::NUMBER => ['inputType' => 'text', 'eval' => ['maxlength' => 32, 'doNotSaveEmpty' => true, 'tl_class' => 'w50']],
            CustomField::DATE => ['inputType' => 'text', 'eval' => ['rgxp' => 'date', 'datepicker' => true, 'doNotSaveEmpty' => true, 'tl_class' => 'w50 wizard']],
            CustomField::BOOLEAN => ['inputType' => 'select', 'options' => ['1', '0'], 'reference' => $lang['customFieldBoolean'] ?? [], 'eval' => ['includeBlankOption' => true, 'doNotSaveEmpty' => true, 'tl_class' => 'w50']],
            default => ['inputType' => 'text', 'eval' => ['maxlength' => CustomFieldValues::TEXT_MAX_LENGTH, 'doNotSaveEmpty' => true, 'tl_class' => 'w50']],
        };
    }

    /**
     * An inherited value as the editor reads it.
     *
     * @param array<string, mixed> $lang
     */
    private static function shown(CustomField $field, string $value, array $lang): string
    {
        if (CustomField::BOOLEAN === $field->type) {
            return (string) ($lang['customFieldBoolean'][$value] ?? $value);
        }

        // As the date field above shows it, not as stored (c5 acceptance,
        // 2026-10-14: "2024-03-01" next to "01.03.2024").
        if (CustomField::DATE === $field->type && false !== ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value))) {
            return $date->format((string) ($GLOBALS['TL_CONFIG']['dateFormat'] ?? 'Y-m-d'));
        }

        return $value;
    }

    /**
     * The custom field a generated field stands for, and its language — ''
     * for the product's own value.
     *
     * @return array{0: CustomField|null, 1: string}
     */
    private function target(DataContainer $dc): array
    {
        $name = (string) $dc->field;

        if (!str_starts_with($name, self::PREFIX)) {
            return [null, ''];
        }

        $rest = substr($name, \strlen(self::PREFIX));
        $fields = $this->fields[(int) $dc->id] ?? $this->values->fieldsOf((int) $dc->id);

        // The whole rest as an alias first: an alias may hold "__" itself.
        foreach ($fields as $field) {
            if ($field->alias === $rest) {
                return [$field, ''];
            }
        }

        $at = strrpos($rest, self::SEPARATOR);

        if (false === $at) {
            return [null, ''];
        }

        $alias = substr($rest, 0, $at);
        $language = substr($rest, $at + \strlen(self::SEPARATOR));

        foreach ($fields as $field) {
            // A translation only for a text field.
            if ($field->alias === $alias && CustomField::TEXT === $field->type && '' !== $language) {
                return [$field, $language];
            }
        }

        return [null, ''];
    }
}

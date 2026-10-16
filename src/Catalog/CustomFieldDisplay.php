<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Webwerkwien\ContaoConshoBundle\Translation\CustomFieldTranslations;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;

/**
 * The custom fields of the article a product page shows (ZW-4; Z3, Michael
 * 2026-10-14: „ja, alles wie empfohlen"): only filled ones, in the order of
 * the product type, the title and a text in the page's language.
 *
 * Which text counts, per field (Z1, Z2):
 * - the article's own value — a variant's override, else its main product's
 * - in a translation language its translation, else the default text; a
 *   variant's own translation first, the main product's only where the
 *   variant does not override the value (it translates the main text)
 *
 * A number and a date are formatted for the language — a number with the
 * decimals it was given and without a thousands separator, since a vintage
 * or a year is the typical number here; yes/no is left to the template's
 * translated word.
 */
class CustomFieldDisplay
{
    public function __construct(
        private readonly CustomFieldValues $values,
        private readonly CustomFieldTranslations $translations,
        private readonly TranslationStorage $storage,
    ) {
    }

    /**
     * @return list<CustomFieldView>
     */
    public function forArticle(int $articleId, string $language, string $fallback): array
    {
        // The fields first: a type without custom fields — most shops, most
        // products — costs two queries here, not a reading of every value. The
        // catalogue JSON asks once per article (review ZW-C, S1).
        $fields = $this->values->fieldsOf($articleId);

        if ([] === $fields) {
            return [];
        }

        $values = $this->values->valuesOf($articleId, $fields);

        if ([] === $values) {
            return [];
        }

        $translated = $language !== $fallback && '' !== $language;
        $texts = $translated ? $this->texts($articleId, $language) : [];
        $views = [];

        foreach ($fields as $field) {
            $value = $values[$field->alias] ?? null;

            if (null === $value) {
                continue;
            }

            if (CustomField::TEXT === $field->type) {
                $value = $texts[$field->alias] ?? $value;
            }

            $title = $translated ? $this->storage->read('tl_consho_custom_field', $field->id, $language, 'title') : '';

            $views[] = new CustomFieldView(
                $field->alias,
                '' !== $title ? $title : $field->title,
                $field->type,
                $value,
                CustomField::BOOLEAN === $field->type ? '' : self::format($field->type, $value, $language),
                CustomField::BOOLEAN === $field->type && '1' === $value,
            );
        }

        return $views;
    }

    /**
     * The translated texts that count for an article in a language.
     *
     * @return array<string, string>
     */
    private function texts(int $articleId, string $language): array
    {
        $pid = $this->values->parentOf($articleId);

        if ($pid < 1) {
            return $this->translations->read($articleId, $language);
        }

        // The main product's translation translates the main product's text:
        // not for a field the variant overrides.
        $main = array_diff_key($this->translations->read($pid, $language), $this->values->ownValues($articleId));

        return [...$main, ...$this->translations->read($articleId, $language)];
    }

    private static function format(string $type, string $value, string $language): string
    {
        $locale = str_replace('-', '_', '' === $language ? 'en' : $language);

        // A value stored under another type — the field was text before — is
        // shown as it is, the way an unreadable date is: as a number "0" it
        // would state something nobody entered.
        if (CustomField::NUMBER === $type && 1 === preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            $decimals = false === ($dot = strpos($value, '.')) ? 0 : \strlen($value) - $dot - 1;
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            // No thousands separator: a vintage 2021 is no "2.021".
            $formatter->setAttribute(\NumberFormatter::GROUPING_USED, 0);

            // Beyond 15 digits a float rounds (9007199254740993 would show as
            // …992): the digits as given, only the separator of the language
            // (Codex review 2026-10-14).
            if (\strlen(ltrim(str_replace(['-', '.'], '', $value), '0')) > 15) {
                return str_replace('.', $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $value);
            }

            $formatted = $formatter->format((float) $value);

            return false === $formatted ? $value : $formatted;
        }

        if (CustomField::DATE === $type) {
            // The round trip as in CustomFieldValues::normalize(): 2024-02-30
            // parses as 1 March, which nobody entered (Codex review 2026-10-14).
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $date = false !== $date && $date->format('Y-m-d') === $value ? $date : false;
            $formatted = false === $date ? false : (new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($date);

            return false === $formatted ? $value : $formatted;
        }

        return $value;
    }
}

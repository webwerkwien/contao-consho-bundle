<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

/**
 * The one source of truth for which fields of which entity are translatable.
 *
 * The same list is needed in four places — the back end mask (M-4), the
 * translation storage (M-4), the version snapshot (M-5) and the gap report
 * (M-6). Keeping it here means it can drift in only one place, not four.
 *
 * The generated field name (`<field>__<language>`) is also the format used
 * by the version snapshot — do not change the separator without updating M-5.
 */
final class TranslatableFields
{
    private const SEPARATOR = '__';

    /** @var array<string, list<string>> */
    private const FIELDS = [
        // The order is the order of the fields in each translation section.
        // customFieldValues: the text values of the custom fields as JSON, per
        // value falling back to the default language (ZW-3, Z1). No input of
        // its own — CustomFieldsListener offers one field per text field.
        'tl_consho_product' => ['title', 'alias', 'teaser', 'metaDescription', 'description', 'safetyWarnings', 'safetyInformation', 'customFieldValues'],
        'tl_consho_product_type' => ['title', 'alias'],
        'tl_consho_manufacturer' => ['title', 'alias'],
        'tl_consho_property_group' => ['title'],
        'tl_consho_property_option' => ['title'],
        'tl_consho_shipping_method' => ['title', 'description'],
        'tl_consho_payment_method' => ['title', 'description', 'instructions'],
        // C-8: the operator's invoice texts, printed in the order's language.
        'tl_consho_shop' => ['invoiceFooter', 'invoiceReverseChargeNote'],
        // ZW-3 (Z1): "Rebsorte:" on a French page would be German otherwise.
        'tl_consho_custom_field' => ['title'],
    ];

    /**
     * The translatable fields of a table, or [] if it has none.
     *
     * @return list<string>
     */
    public static function of(string $table): array
    {
        return self::FIELDS[$table] ?? [];
    }

    /**
     * All tables that have translatable fields.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function fieldName(string $field, string $language): string
    {
        return $field.self::SEPARATOR.$language;
    }

    /**
     * Splits a generated field name back apart. Empty if it is not one.
     *
     * Split on the FIRST occurrence: language codes like de-AT contain
     * hyphens, field names do not contain a double underscore pair.
     *
     * @return list<string>
     */
    public static function split(string $name): array
    {
        $parts = explode(self::SEPARATOR, $name, 2);

        return 2 === \count($parts) && '' !== $parts[1] ? $parts : [];
    }
}

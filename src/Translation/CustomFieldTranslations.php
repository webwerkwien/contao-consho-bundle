<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

use Webwerkwien\ContaoConshoBundle\Catalog\CustomField;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldCodec;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldValues;

/**
 * The text values of a product's custom fields in a translation language
 * (ZW-3, Z1; Michael 2026-10-14: „ja, alles wie empfohlen") — for the mask
 * and a command alike.
 *
 * Only text fields: a number, a date and yes/no read the same in every
 * language. Stored as JSON in the language block's `customFieldValues`,
 * through TranslationWriter::store(), so a version and the rest of the block
 * behave as for any translated field. A text not translated falls back to
 * the default language's, value by value — as `teaser` does.
 */
class CustomFieldTranslations
{
    public function __construct(
        private readonly TranslationStorage $storage,
        private readonly TranslationWriter $writer,
        private readonly CustomFieldValues $values,
        private readonly CustomFieldCodec $codec,
    ) {
    }

    /**
     * The product's translation languages (those of its shops).
     *
     * @return list<string>
     */
    public function languagesOf(int $productId): array
    {
        return $this->writer->languagesOf('tl_consho_product', $productId);
    }

    /**
     * @return list<CustomField>
     */
    public function textFieldsOf(int $productId): array
    {
        return array_values(array_filter($this->values->fieldsOf($productId), static fn (CustomField $field): bool => CustomField::TEXT === $field->type));
    }

    /**
     * The product's own translated texts in a language, alias => text.
     *
     * @return array<string, string>
     */
    public function read(int $productId, string $language): array
    {
        $decoded = $this->codec->decode($this->storage->read('tl_consho_product', $productId, $language, 'customFieldValues'));

        return array_map(strval(...), array_filter($decoded, static fn (mixed $v): bool => \is_string($v) && '' !== $v));
    }

    /**
     * Changes some translated texts; an empty one removes the translation
     * (the default language's text applies again).
     *
     * @param array<string, string> $input alias => text
     *
     * @throws TranslationRefused naming the field, the language or the reason
     *
     * @return bool whether the stored block changed
     */
    public function set(int $productId, string $language, array $input): bool
    {
        $offered = $this->languagesOf($productId);

        if (!\in_array($language, $offered, true)) {
            throw new TranslationRefused(\sprintf('"%s" is no translation language of product %d. Offered: %s.', $language, $productId, implode(', ', $offered) ?: 'none'));
        }

        $fields = [];

        foreach ($this->textFieldsOf($productId) as $field) {
            $fields[$field->alias] = $field;
        }

        $texts = $this->read($productId, $language);

        foreach ($input as $alias => $raw) {
            $field = $fields[$alias] ?? throw new TranslationRefused(\sprintf('"%s" is no translatable custom field of this product\'s type (text fields only). Fields: %s.', $alias, implode(', ', array_keys($fields)) ?: 'none'));

            try {
                $text = CustomFieldValues::normalize($field, $raw);
            } catch (\InvalidArgumentException $e) {
                throw new TranslationRefused($e->getMessage(), 0, $e);
            }

            if (null === $text) {
                unset($texts[$alias]);
            } else {
                $texts[$alias] = $text;
            }
        }

        return $this->writer->store('tl_consho_product', $productId, $language, ['customFieldValues' => [] === $texts ? '' : $this->codec->encode($texts)]);
    }
}

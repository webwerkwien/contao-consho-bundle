<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The values of a product's custom fields (block "Zusatzfeld-Werte", ZW-1;
 * decisions Z1–Z5, Michael 2026-10-14: „ja, alles wie empfohlen") — one rule
 * for the mask, the product page and a command.
 *
 * - Which fields a product has comes from the product type of its main
 *   product: a variant has no type of its own.
 * - A variant inherits every value of its main product and may override it
 *   one by one; no value of its own means it inherits (Z2).
 * - Stored as one JSON object, alias => string, through CustomFieldCodec:
 *   text trimmed, a number with a point, a date as YYYY-MM-DD, yes/no as
 *   "1"/"0". An empty input removes the value. Not filterable (A1).
 */
class CustomFieldValues
{
    public const TEXT_MAX_LENGTH = 255;

    public function __construct(
        private readonly Connection $connection,
        private readonly CustomFieldCodec $codec,
    ) {
    }

    /**
     * The fields of a product, in the order its type lists them.
     *
     * @return list<CustomField>
     */
    public function fieldsOf(int $productId): array
    {
        $type = $this->connection->fetchOne(
            'SELECT CASE WHEN p.pid > 0 THEN m.productType ELSE p.productType END FROM tl_consho_product p
                LEFT JOIN tl_consho_product m ON m.id = p.pid AND p.pid > 0
                WHERE p.id = ?',
            [$productId],
        );

        return $this->fieldsOfType((int) $type);
    }

    /**
     * The fields of a product type, in its order — for a product that does
     * not exist yet: a command checks its values before anything is written.
     *
     * @return list<CustomField>
     */
    public function fieldsOfType(int $typeId): array
    {
        $type = $typeId < 1 ? false : $this->connection->fetchOne('SELECT customFields FROM tl_consho_product_type WHERE id = ?', [$typeId]);

        $ids = array_values(array_filter(array_map('intval', StringUtil::deserialize(false === $type ? null : $type, true)), static fn (int $id): bool => $id > 0));

        if ([] === $ids) {
            return [];
        }

        $rows = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, alias, title, type FROM tl_consho_custom_field WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        $fields = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;

            // A field deleted from the list, or one with a type this code
            // does not know, has no input and no output.
            if (null === $row || '' === (string) $row['alias'] || !\in_array((string) $row['type'], CustomField::TYPES, true)) {
                continue;
            }

            $fields[] = new CustomField($id, (string) $row['alias'], (string) $row['title'], (string) $row['type']);
        }

        return $fields;
    }

    /**
     * The product's own values, alias => value, as stored.
     *
     * @return array<string, string>
     */
    public function ownValues(int $productId): array
    {
        $json = $this->connection->fetchOne('SELECT customFieldValues FROM tl_consho_product WHERE id = ?', [$productId]);

        return self::strings($this->codec->decode(false === $json ? null : (string) $json));
    }

    /**
     * The values that count for a product: a variant's own over its main
     * product's (Z2), only for fields its type has.
     *
     * @param list<CustomField>|null $fields the product's fields when the caller has them already
     *
     * @return array<string, string>
     */
    public function valuesOf(int $productId, array|null $fields = null): array
    {
        $pid = $this->parentOf($productId);
        $values = $pid > 0 ? [...$this->ownValues($pid), ...$this->ownValues($productId)] : $this->ownValues($productId);
        $known = array_map(static fn (CustomField $field): string => $field->alias, $fields ?? $this->fieldsOf($productId));

        return array_intersect_key($values, array_flip($known));
    }

    /**
     * The main product of a variant; 0 for a main product.
     */
    public function parentOf(int $productId): int
    {
        return (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$productId]);
    }

    /**
     * The column value for a set of own values; null for none. Storing is the
     * caller's: the mask saves through DC_Table, a command through contao-ai's
     * writer — both leave a version, a write from here would not.
     *
     * @param array<string, string> $values
     */
    public function column(array $values): string|null
    {
        return [] === $values ? null : $this->codec->encode($values);
    }

    /**
     * The new own values after an input, not stored.
     *
     * @param array<string, string> $stored
     * @param list<CustomField>     $fields
     * @param array<string, string> $input alias => raw input; empty removes
     *
     * @throws \InvalidArgumentException naming the field and the reason
     *
     * @return array<string, string>
     */
    public function merge(array $stored, array $fields, array $input): array
    {
        $byAlias = [];

        foreach ($fields as $field) {
            $byAlias[$field->alias] = $field;
        }

        foreach ($input as $alias => $raw) {
            $field = $byAlias[$alias] ?? throw new \InvalidArgumentException(\sprintf('"%s" is no custom field of this product\'s type. Fields: %s.', $alias, [] === $byAlias ? 'none' : implode(', ', array_keys($byAlias))));
            $value = self::normalize($field, $raw);

            if (null === $value) {
                unset($stored[$alias]);
            } else {
                $stored[$alias] = $value;
            }
        }

        return $stored;
    }

    /**
     * One input in its stored form; null for empty (the value is removed).
     *
     * @throws \InvalidArgumentException
     */
    public static function normalize(CustomField $field, string $raw): string|null
    {
        $value = trim($raw);

        if ('' === $value) {
            return null;
        }

        switch ($field->type) {
            case CustomField::NUMBER:
                if (1 !== preg_match('/^-?[0-9]+(?:[.,][0-9]+)?\z/', $value)) {
                    throw new \InvalidArgumentException(\sprintf('%s: "%s" is no number (e.g. 12 or 12.5).', $field->alias, $value));
                }

                return str_replace(',', '.', $value);

            case CustomField::DATE:
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

                if (false === $date || $date->format('Y-m-d') !== $value) {
                    throw new \InvalidArgumentException(\sprintf('%s: "%s" is no date as YYYY-MM-DD.', $field->alias, $value));
                }

                return $value;

            case CustomField::BOOLEAN:
                if (!\in_array($value, ['0', '1'], true)) {
                    throw new \InvalidArgumentException(\sprintf('%s: give 1 for yes or 0 for no, empty for none.', $field->alias));
                }

                return $value;

            default:
                // One line, as the mask's widget: a command could store a
                // line break, which would break the Markdown list (review
                // ZW-C, S2). Checked here, so mask, command and translation
                // refuse alike.
                if (1 === preg_match('/[\x00-\x1F\x7F]/u', $value)) {
                    throw new \InvalidArgumentException(\sprintf('%s: the text holds a line break or another control character.', $field->alias));
                }

                if (mb_strlen($value) > self::TEXT_MAX_LENGTH) {
                    throw new \InvalidArgumentException(\sprintf('%s: the text is longer than %d characters.', $field->alias, self::TEXT_MAX_LENGTH));
                }

                return $value;
        }
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array<string, string>
     */
    private static function strings(array $decoded): array
    {
        $values = [];

        foreach ($decoded as $alias => $value) {
            if (\is_scalar($value) && '' !== (string) $value) {
                $values[(string) $alias] = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $values;
    }
}

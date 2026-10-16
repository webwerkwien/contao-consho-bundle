<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * Restricts alias uniqueness to a subset of a table.
 *
 * Property options need one dimension: the filter state spells an option as
 * "colour:red", so the group is already part of the address and "rot" may
 * exist once per group. Table-wide uniqueness would turn the second one into
 * "rot-2" — a worse address, for no gain.
 *
 * Translations need two: a product alias, a manufacturer alias and a product
 * type alias all live in the same tl_consho_translation table, one row per
 * language. Scoping only by language would let an English product alias
 * block a French one; scoping only by parent table would let a manufacturer
 * block a product for an alias that sits at a different point in the URL.
 * Both dimensions together match the real unique index — ptable, language,
 * alias — in contao/dca/tl_consho_translation.php; either alone would not.
 */
final class AliasScope
{
    public function __construct(
        public readonly string $column,
        public readonly int|string $value,
        public readonly string|null $secondColumn = null,
        public readonly int|string|null $secondValue = null,
    ) {
    }

    /**
     * Die Bedingungen als Spalte => Wert.
     *
     * @return array<string, int|string>
     */
    public function conditions(): array
    {
        $conditions = [$this->column => $this->value];

        if (null !== $this->secondColumn) {
            $conditions[$this->secondColumn] = $this->secondValue ?? '';
        }

        return $conditions;
    }
}

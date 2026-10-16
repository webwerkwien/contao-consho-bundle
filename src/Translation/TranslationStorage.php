<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

use Doctrine\DBAL\Connection;

/**
 * Reads and writes one language block of tl_consho_translation as a unit.
 *
 * Deliberately without a Contao model: this is used from DCA callbacks and
 * from the version snapshot (M-5), which work with rows, not with objects
 * that carry a lifecycle of their own.
 */
final class TranslationStorage
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * One translated field, or '' if there is no row or the field is not
     * translatable for this table.
     */
    public function read(string $ptable, int $pid, string $language, string $field): string
    {
        if (!\in_array($field, TranslatableFields::of($ptable), true)) {
            return '';
        }

        $value = $this->connection->fetchOne(
            \sprintf(
                'SELECT %s FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid AND language = :language',
                $field,
            ),
            ['ptable' => $ptable, 'pid' => $pid, 'language' => $language],
        );

        return false === $value ? '' : (string) $value;
    }

    /**
     * All translatable fields of the table for one language block.
     *
     * Only the fields from TranslatableFields::of() come back — never id,
     * pid, ptable or tstamp. NULL becomes ''. No row at all → [].
     *
     * @return array<string, string>
     */
    public function readAll(string $ptable, int $pid, string $language): array
    {
        $fields = TranslatableFields::of($ptable);

        if ([] === $fields) {
            return [];
        }

        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT %s FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid AND language = :language',
                implode(', ', $fields),
            ),
            ['ptable' => $ptable, 'pid' => $pid, 'language' => $language],
        );

        return false === $row ? [] : array_map(static fn ($v): string => (string) $v, $row);
    }

    /**
     * Replaces the WHOLE language block — not just the given fields.
     *
     * Deliberate: any translatable field missing from $values counts as
     * empty and is cleared. A partial block that happens to be empty would
     * otherwise delete a row whose other fields are still filled in.
     * Callers (the mask, the version restore) always pass the complete
     * block.
     *
     * Unknown keys are dropped silently. An empty alias is stored as NULL —
     * the unique index (ptable, language, alias) tolerates any number of
     * NULLs but not two empty strings. If every translatable field ends up
     * empty, the row is deleted instead of kept around empty: per the
     * specification, a row's mere existence means the entity exists in that
     * language.
     *
     * @param array<string, string> $values
     */
    public function write(string $ptable, int $pid, string $language, array $values): void
    {
        $fields = TranslatableFields::of($ptable);

        if ([] === $fields) {
            return;
        }

        $values = array_intersect_key($values, array_flip($fields));

        $row = [];

        foreach ($fields as $field) {
            $row[$field] = $values[$field] ?? '';
        }

        if ([] === array_filter($row, static fn (string $value): bool => '' !== $value)) {
            $this->connection->delete('tl_consho_translation', [
                'ptable' => $ptable,
                'pid' => $pid,
                'language' => $language,
            ]);

            return;
        }

        if (\in_array('alias', $fields, true) && '' === $row['alias']) {
            $row['alias'] = null;
        }

        /*
         * Select, then update or insert — not an upsert. ON DUPLICATE KEY
         * UPDATE is MySQL only and the tests run on SQLite; RedirectRecorder
         * takes the same route for the same reason.
         *
         * The gap between the two statements is accepted. Both callers hold
         * the parent record open in Contao's edit mask, which already warns a
         * second editor off. If two saves do meet, the unique index
         * (ptable, pid, language) rejects the second INSERT: an error for one
         * editor, never a duplicated or half-written block.
         */
        $id = $this->connection->fetchOne(
            'SELECT id FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid AND language = :language',
            ['ptable' => $ptable, 'pid' => $pid, 'language' => $language],
        );

        $row['tstamp'] = time();

        if (false !== $id) {
            $this->connection->update('tl_consho_translation', $row, ['id' => $id]);

            return;
        }

        $this->connection->insert('tl_consho_translation', [
            'ptable' => $ptable,
            'pid' => $pid,
            'language' => $language,
            ...$row,
        ]);
    }

    /**
     * The id of the translation row itself, or 0 if there is none yet.
     *
     * Needed by the alias uniqueness check, which excludes "this record" by
     * id. That record is the translation row — passing the parent's id
     * instead would exclude an unrelated row that happens to share the
     * number, and fail to exclude the row being saved.
     */
    public function rowId(string $ptable, int $pid, string $language): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT id FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid AND language = :language',
            ['ptable' => $ptable, 'pid' => $pid, 'language' => $language],
        );
    }

    /**
     * The shops a product is assigned to directly.
     *
     * Only the product's own rows. A variant with none inherits its main
     * product's shops (specification A, 3.7); that rule belongs to the
     * caller, which knows whether it is looking at a variant.
     *
     * @return list<int>
     */
    public function shopsOfProduct(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT DISTINCT shop FROM tl_consho_product_shop WHERE pid = ? ORDER BY shop',
            [$productId],
        ));
    }

    /**
     * The main product of a variant, 0 for a main product or none.
     */
    public function parentOfProduct(int $productId): int
    {
        return (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$productId]);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

use Doctrine\DBAL\Connection;

/**
 * Carries the translations inside the parent record's own Contao version —
 * Block M, 7.4 and 7.5.
 *
 * ── Why the callbacks and not something simpler ───────────────────────────
 *
 * Versions::create() stores SELECT * of the parent row only. The version
 * number is known only after its INSERT, and oncreate_version_callback is the
 * one place that receives it. So the snapshot is written into the row Contao
 * has just inserted, and Contao's own purging of old versions takes the
 * translations along.
 *
 * ── The shape is required, not a matter of taste ──────────────────────────
 *
 * Flat, under the field names of the mask (`title__en`). compare() iterates
 * over the stored keys and labels each through the DCA — stored as one nested
 * array it would show an unreadable block diff.
 *
 * ── What a key, and a missing key, means ──────────────────────────────────
 *
 * The snapshot has every field for every language that is offered anywhere
 * or has a row, empty where there is nothing. An empty value therefore says
 * "no translation in this state", and restoring it removes the row. A version
 * without ANY translation key predates M-5 (or any translation language); it
 * knows nothing about translations, and restoring it leaves them alone —
 * deleting them would destroy text that no version holds.
 */
final class TranslationVersioning
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationStorage $storage,
        private readonly ShopLanguages $languages,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function snapshot(string $ptable, int $pid): array
    {
        $fields = TranslatableFields::of($ptable);

        if ([] === $fields) {
            return [];
        }

        $rows = [];

        foreach ($this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT language, %s FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid',
                implode(', ', $fields),
            ),
            ['ptable' => $ptable, 'pid' => $pid],
        ) as $row) {
            $rows[(string) $row['language']] = $row;
        }

        /*
         * All offered languages, not only the product's shops: which shops a
         * product is in can change between two versions, and a language left
         * out here would read as "predates M-5" for exactly that language.
         * Rows of languages no shop offers any more are kept too — hidden is
         * not deleted.
         */
        $languages = array_values(array_unique([...$this->languages->allTranslated(), ...array_map(strval(...), array_keys($rows))]));
        sort($languages);

        $snapshot = [];

        foreach ($languages as $language) {
            foreach ($fields as $field) {
                $snapshot[TranslatableFields::fieldName($field, $language)] = (string) ($rows[$language][$field] ?? '');
            }
        }

        return $snapshot;
    }

    /**
     * Adds the snapshot to a version row Contao has already written.
     *
     * It reads the current state of tl_consho_translation. DC_Table calls
     * Versions::create() after the onsubmit callbacks (DC_Table::submit()),
     * and TranslationFieldsListener::store() writes there — so a version
     * created on save holds what was just saved.
     */
    public function store(string $ptable, int $pid, int $version): void
    {
        $snapshot = $this->snapshot($ptable, $pid);

        // Monolingual: the version row stays byte for byte what it was.
        if ([] === $snapshot) {
            return;
        }

        $data = $this->versionData($ptable, $pid, $version);

        if (null === $data) {
            return;
        }

        $this->connection->update(
            'tl_version',
            ['data' => serialize([...$data, ...$snapshot])],
            ['fromTable' => $ptable, 'pid' => $pid, 'version' => $version],
        );
    }

    /**
     * Writes the translations of a version back.
     *
     * The tl_version row is read here AGAIN although the callback receives
     * $data: Versions::restore() has reduced it to the parent table's columns
     * with array_intersect_key() before calling back, so our keys are gone.
     */
    public function restore(string $ptable, int $pid, int $version): void
    {
        $fields = TranslatableFields::of($ptable);
        $data = [] === $fields ? null : $this->versionData($ptable, $pid, $version);

        if (null === $data) {
            return;
        }

        $blocks = [];

        foreach ($data as $key => $value) {
            $parts = TranslatableFields::split((string) $key);

            if ([] === $parts || !\in_array($parts[0], $fields, true)) {
                continue;
            }

            $blocks[$parts[1]][$parts[0]] = \is_scalar($value) ? (string) $value : '';
        }

        if ([] === $blocks) {
            return;
        }

        // A language with a row now but no key in the version did not exist
        // in that state; an empty block makes write() delete it.
        foreach ($this->connection->fetchFirstColumn(
            'SELECT language FROM tl_consho_translation WHERE ptable = ? AND pid = ?',
            [$ptable, $pid],
        ) as $language) {
            $blocks[(string) $language] ??= [];
        }

        foreach ($blocks as $language => $values) {
            $language = (string) $language;

            if ($this->aliasTaken($ptable, $pid, $language, $values['alias'] ?? '')) {
                /*
                 * Emptied, the way Versions::restore() treats the parent's own
                 * unique fields (contao/core-bundle#698). Failing on the index
                 * instead would be an error page after the parent row has been
                 * restored already. Saving the record generates a fresh alias
                 * from the title (TranslationFieldsListener::store()).
                 */
                $values['alias'] = '';
            }

            $this->storage->write($ptable, $pid, $language, $values);
        }
    }

    private function aliasTaken(string $ptable, int $pid, string $language, string $alias): bool
    {
        if ('' === $alias) {
            return false;
        }

        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_consho_translation WHERE ptable = ? AND language = ? AND alias = ? AND pid != ?',
            [$ptable, $language, $alias, $pid],
        );
    }

    /**
     * @return array<mixed>|null
     */
    private function versionData(string $table, int $pid, int $version): array|null
    {
        $raw = $this->connection->fetchOne(
            'SELECT data FROM tl_version WHERE fromTable = ? AND pid = ? AND version = ?',
            [$table, $pid, $version],
        );

        if (false === $raw || null === $raw) {
            return null;
        }

        $data = @unserialize((string) $raw, ['allowed_classes' => false]);

        return \is_array($data) ? $data : null;
    }
}

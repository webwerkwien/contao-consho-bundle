<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Connection;

/**
 * Generates and checks aliases for the catalogue tables.
 *
 * Three tables need the same twenty lines — product, manufacturer, product
 * type — and the third copy is the one that quietly drops the numeric check.
 *
 * ── Why aliases and not slugified titles at render time ───────────────────
 *
 * An alias is a value the editor owns and that does not move when the title is
 * corrected. Shopware puts `{{ product.translated.name }}` in its URL template,
 * and a renamed article changes its address; Contao uses aliases for news and
 * events for exactly this reason. See Block B,
 * section 4.2.
 */
final class AliasFactory
{
    public function __construct(
        private readonly Slug $slug,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param string $typed what the editor entered, possibly empty
     * @param string $title the fallback the alias is generated from
     *
     * @throws \RuntimeException when the typed alias is numeric or taken
     */
    public function forRecord(string $table, string $typed, string $title, int $id, ?AliasScope $scope = null): string
    {
        $typed = trim($typed);

        if ('' !== $typed) {
            /*
             * A purely numeric alias would be indistinguishable from an ID in a
             * URL segment, and Contao refuses it for its own tables too.
             */
            if (preg_match('/^[1-9]\d*$/', $typed)) {
                throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['ERR']['aliasNumeric'] ?? 'Alias "%s" must not be numeric.', $typed));
            }

            if ($this->exists($table, $typed, $id, $scope)) {
                throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['ERR']['aliasExists'] ?? 'Alias "%s" already exists.', $typed));
            }

            return $typed;
        }

        /*
         * No slug options are passed. They come from the root page a record is
         * published under, and catalogue records are not bound to one — a
         * product can be visible in several shops. Contao's defaults apply.
         */
        return $this->slug->generate(
            $title,
            [],
            fn (string $candidate): bool => $this->exists($table, $candidate, $id, $scope),
        );
    }

    /**
     * Whether the alias is taken — table-wide, or within a scope.
     *
     * A property option only needs to be unique inside its group: "rot" may
     * exist once under Farbe and once under Lackfarbe, because the filter state
     * spells it "colour:red" and the group is part of the address. Forcing
     * table-wide uniqueness would make the second one "rot-2", which is a worse
     * address for no gain.
     */
    private function exists(string $table, string $alias, int $id, ?AliasScope $scope): bool
    {
        /*
         * The table and scope columns are interpolated because they cannot be
         * parameters. Neither comes from input — every caller passes literals.
         * One or two columns can come from the scope's conditions(); each adds
         * its own "column = ?" and binds its value the same way as $alias and
         * $id below.
         */
        $sql = \sprintf('SELECT COUNT(*) FROM %s WHERE alias = ? AND id != ?', $table);
        $params = [$alias, $id];

        if (null !== $scope) {
            foreach ($scope->conditions() as $column => $value) {
                $sql .= \sprintf(' AND %s = ?', $column);
                $params[] = $value;
            }
        }

        return (bool) $this->connection->fetchOne($sql, $params);
    }
}

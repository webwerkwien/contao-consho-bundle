<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The text elements a root page may point at for its terms and its withdrawal
 * instruction (C17).
 *
 * Offered are the elements of type "text" below THIS root page, grouped by the
 * page they sit on. The page is not a second field: it follows from the
 * element, and the whole reason is written down at consho_termsElement in
 * tl_page.php.
 *
 * ⚠️ Elements nested inside another element (ptable = 'tl_content') are not
 * offered. They would need their owner resolved recursively to name a page,
 * and a terms text does not live inside an accordion. Everything the operator
 * can pick here can also be found again by the findings.
 *
 * The tree walk is levelwise, not a recursive CTE: MySQL 5.7 is still within
 * Contao's supported range and has none. This runs when a mask is opened, not
 * on a page request — the findings never walk the tree (11.3, "count queries
 * only").
 */
class LegalTextOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int|string, string>
     */
    #[AsCallback(table: 'tl_page', target: 'fields.consho_termsElement.options')]
    public function terms(DataContainer|null $dc = null): array
    {
        return $this->options(null === $dc ? 0 : (int) $dc->id);
    }

    /**
     * @return array<int|string, string>
     */
    #[AsCallback(table: 'tl_page', target: 'fields.consho_withdrawalElement.options')]
    public function withdrawal(DataContainer|null $dc = null): array
    {
        return $this->options(null === $dc ? 0 : (int) $dc->id);
    }

    /**
     * ⚠️ array<int|string, string>, not array<string, string>: a numeric
     * string as an array key silently becomes an int in PHP. Contao does
     * not care, PHPStan does — and the promise "strings only" would simply
     * be untrue.
     *
     * @return array<int|string, string>
     */
    private function options(int $rootId): array
    {
        if ($rootId <= 0) {
            return [];
        }

        $pages = $this->subtree($rootId);

        if ([] === $pages) {
            return [];
        }

        /** @var list<array{id: int, headline: string, text: string, page: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.id, c.headline, c.text, p.title AS page
               FROM tl_content c
               JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article'
               JOIN tl_page p ON p.id = a.pid
              WHERE c.type = 'text' AND p.id IN (:pages)
              ORDER BY p.title, a.sorting, c.sorting",
            ['pages' => $pages],
            ['pages' => ArrayParameterType::INTEGER],
        );

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['id']] = $row['page'].': '.$this->nameOf($row['headline'], $row['text']);
        }

        return $options;
    }

    /**
     * What to call an element in the list.
     *
     * The headline first, because that is what the operator named it. Without
     * one the beginning of the text has to do — an element with neither is
     * still offered under its ID, because refusing to list it would leave the
     * operator with a picker that silently omits their element.
     */
    private function nameOf(string $headline, string $text): string
    {
        $unserialized = unserialize($headline, ['allowed_classes' => false]);
        $value = \is_array($unserialized) ? (string) ($unserialized['value'] ?? '') : $headline;

        if ('' === trim($value)) {
            $value = trim(strip_tags($text));
            $value = mb_substr($value, 0, 60).(mb_strlen($value) > 60 ? '…' : '');
        }

        return '' === $value ? '#' : $value;
    }

    /**
     * @return list<int>
     */
    private function subtree(int $rootId): array
    {
        $all = [$rootId];
        $level = [$rootId];

        // A guard against a cycle in tl_page: a page whose pid chain loops
        // would spin here forever. Contao does not create one, a hand-written
        // UPDATE can.
        for ($depth = 0; $depth < 32 && [] !== $level; ++$depth) {
            /** @var list<int> $level */
            $level = array_map('intval', $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_page WHERE pid IN (:pids)',
                ['pids' => $level],
                ['pids' => ArrayParameterType::INTEGER],
            ));

            $level = array_values(array_diff($level, $all));
            $all = array_merge($all, $level);
        }

        return $all;
    }
}

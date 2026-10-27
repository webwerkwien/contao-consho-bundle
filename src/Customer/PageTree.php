<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;

/**
 * The site structure as far as access goes: which start point a page belongs
 * to, and which page protects it.
 *
 * Protection is inherited the way PageModel::loadDetails() inherits it: the
 * nearest protected page on the way up decides, with its groups — the page
 * itself first, the start point last.
 *
 * Read once per check: a findings run asks for a handful of pages, and one
 * query over tl_page is cheaper than a walk of queries per page.
 */
final class PageTree
{
    /**
     * @param array<int, array<string, mixed>> $pages by id
     */
    private function __construct(private readonly array $pages)
    {
    }

    public static function load(Connection $connection): self
    {
        $pages = [];

        foreach ($connection->fetchAllAssociative('SELECT id, pid, type, title, protected, '.$connection->quoteIdentifier('groups').' AS grp, consho_shop FROM tl_page') as $row) {
            $pages[(int) $row['id']] = $row;
        }

        return new self($pages);
    }

    public function title(int $pageId): string
    {
        return (string) ($this->pages[$pageId]['title'] ?? '');
    }

    /**
     * The start point above the page, or the page itself if it is one.
     *
     * @return array<string, mixed>|null
     */
    public function rootOf(int $pageId): array|null
    {
        return $this->walk($pageId)[0];
    }

    /**
     * The nearest protected page on the way up, the page itself included;
     * null where nothing protects it.
     *
     * @return array<string, mixed>|null
     */
    public function guardOf(int $pageId): array|null
    {
        return $this->walk($pageId)[1];
    }

    /**
     * The member groups the protecting page admits; null where the page is
     * not protected at all.
     *
     * @return list<int>|null
     */
    public function admittedGroups(int $pageId): array|null
    {
        $guard = $this->guardOf($pageId);

        return null === $guard ? null : array_map('intval', StringUtil::deserialize($guard['grp'] ?? null, true));
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function walk(int $pageId): array
    {
        $root = null;
        $guard = null;

        // Up to the start point; the first protected page decides. The step
        // limit stops a pid loop a broken import may leave.
        for ($id = $pageId, $steps = 0; isset($this->pages[$id]) && $steps < 100; $id = (int) $this->pages[$id]['pid'], ++$steps) {
            if (null === $guard && !empty($this->pages[$id]['protected'])) {
                $guard = $this->pages[$id];
            }

            if ('root' === $this->pages[$id]['type'] || 'rootfallback' === $this->pages[$id]['type']) {
                $root = $this->pages[$id];

                break;
            }
        }

        return [$root, $guard];
    }
}

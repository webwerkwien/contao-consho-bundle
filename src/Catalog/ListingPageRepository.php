<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;

/**
 * Where the listing elements of a page are.
 *
 * Only elements directly in articles count as being on a page. A listing placed
 * in a layout section (ptable tl_theme) belongs to no page in particular, and a
 * listing nested in another element (ptable tl_content) is not looked for; the
 * filter element then needs conshoListElement set explicitly.
 *
 * "Visible" is Contao's own rule for the front end, as in
 * ContentModel::findPublishedByPidAndTable() and ArticleModel: element not
 * invisible, article published, both within start and stop. start and stop are
 * varchar(10) with '' for "not set", not integers with 0.
 */
final class ListingPageRepository
{
    private const TYPE = 'consho_product_list';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The first visible listing on the page that follows the address — in the
     * order the page renders its articles and their elements.
     */
    public function firstOnPage(int $pageId, int $now): ?ListingElement
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT c.id, a.pid AS pageId, c.conshoFilter, c.conshoShowCounts, c.conshoFollowsAddress
                   FROM tl_content c
                   JOIN tl_article a ON a.id = c.pid AND c.ptable = \'tl_article\'
                  WHERE a.pid = :page AND c.type = :type AND c.conshoFollowsAddress = 1
                    AND %s
                  ORDER BY a.sorting, a.id, c.sorting, c.id
                  LIMIT 1',
                $this->visible(),
            ),
            ['page' => $pageId, 'type' => self::TYPE, 'now' => $now],
        );

        return false === $row ? null : $this->element($row);
    }

    /**
     * A listing by its id, whether it follows the address or not and whether
     * it is visible or not — the caller decides what either means for it.
     */
    public function byId(int $elementId): ?ListingElement
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.id, a.pid AS pageId, c.conshoFilter, c.conshoShowCounts, c.conshoFollowsAddress
               FROM tl_content c
               LEFT JOIN tl_article a ON a.id = c.pid AND c.ptable = \'tl_article\'
              WHERE c.id = :id AND c.type = :type',
            ['id' => $elementId, 'type' => self::TYPE],
        );

        return false === $row ? null : $this->element($row);
    }

    /**
     * Every page carrying a visible listing that follows the address.
     *
     * @return array<int, string> page id => page title
     */
    public function pagesWithList(int $now): array
    {
        /** @var array<int, string> $pages */
        $pages = $this->connection->fetchAllKeyValue(
            \sprintf(
                'SELECT p.id, p.title
                   FROM tl_page p
                  WHERE EXISTS (
                        SELECT 1
                          FROM tl_content c
                          JOIN tl_article a ON a.id = c.pid AND c.ptable = \'tl_article\'
                         WHERE a.pid = p.id AND c.type = :type AND c.conshoFollowsAddress = 1
                           AND %s
                  )
                  ORDER BY p.title, p.id',
                $this->visible(),
            ),
            ['type' => self::TYPE, 'now' => $now],
        );

        return $pages;
    }

    /**
     * Every visible listing anywhere — in an article, in a layout section or
     * nested in another element (plan B-8, consho:catalog:verify).
     *
     * Outside an article only the element's own switches count, and pageId is
     * 0: a layout section serves every page using the layout, so the caller
     * has to treat such a listing as reaching every start point.
     *
     * @return list<ListingElement>
     */
    public function allVisible(int $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.id, CASE WHEN c.ptable = 'tl_article' THEN a.pid ELSE 0 END AS pageId,
                    c.conshoFilter, c.conshoShowCounts, c.conshoFollowsAddress, c.conshoPinsOnly
               FROM tl_content c
               LEFT JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article'
              WHERE c.type = :type AND c.invisible = 0 AND c.tstamp != 0
                AND (c.start = '' OR c.start <= :now) AND (c.stop = '' OR c.stop > :now)
                AND (c.ptable != 'tl_article' OR (
                    a.id IS NOT NULL AND a.published = 1
                    AND (a.start = '' OR a.start <= :now) AND (a.stop = '' OR a.stop > :now)
                ))
              ORDER BY c.id",
            ['type' => self::TYPE, 'now' => (string) $now],
        );

        return array_map($this->element(...), $rows);
    }

    private function visible(): string
    {
        // tstamp = 0: the row exists but was never actually saved (#2708) — the
        // same guard as ContentModel::findPublishedByPidAndTable().
        return "c.invisible = 0 AND c.tstamp != 0
            AND (c.start = '' OR c.start <= :now) AND (c.stop = '' OR c.stop > :now)
            AND a.published = 1
            AND (a.start = '' OR a.start <= :now) AND (a.stop = '' OR a.stop > :now)";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function element(array $row): ListingElement
    {
        return new ListingElement(
            (int) $row['id'],
            (int) ($row['pageId'] ?? 0),
            $this->preset($row['conshoFilter'] ?? null),
            (bool) $row['conshoShowCounts'],
            (bool) $row['conshoFollowsAddress'],
            (bool) ($row['conshoPinsOnly'] ?? false),
        );
    }

    /**
     * The same reading as ProductListController::presetOptions(), which moves
     * here in a later step: a serialized list of option ids, anything else
     * dropped.
     *
     * @return list<int>
     */
    private function preset(mixed $stored): array
    {
        $values = StringUtil::deserialize($stored, true);

        return array_values(array_map(intval(...), array_filter($values, is_numeric(...))));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The page cache of a start point (block I, I4b): what is set, the one switch
 * the setup page offers, and which pages "Jetzt messen" asks for.
 *
 * Whether pages are cached stays the operator's decision (spec B 9.1) — no
 * recommendation here, and the switch only turns on what Contao offers in
 * the start point's own mask: `includeCache` with one hour. Pages below that
 * set their own value keep it, as they would after a save in the mask.
 */
final class PageCacheSetup
{
    public const SECONDS = 3600;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly UrlGeneratorInterface $router,
        private readonly ContentUrlGenerator|null $urls = null,
        private readonly CacheTagManager|null $tags = null,
    ) {
    }

    /**
     * @return array{enabled: bool, seconds: int}
     */
    public function state(int $rootId): array
    {
        $row = $this->connection->fetchAssociative('SELECT includeCache, cache FROM tl_page WHERE id = ?', [$rootId]);

        return [
            'enabled' => false !== $row && (bool) $row['includeCache'] && (int) $row['cache'] > 0,
            'seconds' => false === $row ? 0 : (int) $row['cache'],
        ];
    }

    /**
     * With a version before and after, like a save in the mask.
     */
    public function enable(int $rootId, int $now): void
    {
        $this->framework->initialize();

        $versions = $this->framework->createInstance(Versions::class, ['tl_page', $rootId]);
        $versions->setEditUrl($this->router->generate('contao_backend', ['do' => 'page', 'act' => 'edit', 'id' => $rootId]));
        $versions->initialize();

        $this->connection->update('tl_page', ['includeCache' => 1, 'cache' => self::SECONDS, 'tstamp' => $now], ['id' => $rootId]);

        $versions->create();
        $this->tags?->invalidateTags(['contao.db.tl_page.'.$rootId]);
    }

    /**
     * The first page a visitor lands on, and the first page below this start
     * point carrying a product list — the pages a cache is for. The shop's
     * own pages answer `private` anyway and would measure nothing.
     *
     * @return list<array{title: string, url: string}>
     */
    public function targets(int $rootId): array
    {
        $this->framework->initialize();

        /*
         * The page "/" serves: the one with the alias `index`, else the first.
         * Three pages under a start point once
         * shared `sorting = 0`, and "the first" was a product page, not Home.
         */
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_page WHERE pid = ? AND type = 'regular' AND published = 1
              ORDER BY CASE WHEN alias = 'index' THEN 0 ELSE 1 END, sorting, id LIMIT 1",
            [$rootId],
        ));

        $catalogue = $this->catalogue($rootId);

        if (null !== $catalogue) {
            $ids[] = $catalogue;
        }

        $pages = $this->framework->getAdapter(PageModel::class);
        $targets = [];

        foreach (array_unique($ids) as $id) {
            $page = $pages->findById($id);

            if (!$page instanceof PageModel || null === $this->urls) {
                continue;
            }

            try {
                $targets[] = ['title' => (string) $page->title, 'url' => $this->urls->generate($page, [], UrlGeneratorInterface::ABSOLUTE_URL)];
            } catch (ExceptionInterface) {
                // Not routable — nothing to measure there.
            }
        }

        return $targets;
    }

    /**
     * The first published page below this start point with a product list —
     * searched in the start point's own tree (I: the first fifty
     * of the whole installation may all belong to other start points).
     */
    private function catalogue(int $rootId): int|null
    {
        $id = $this->connection->fetchOne(
            "WITH RECURSIVE tree (id) AS (
                 SELECT id FROM tl_page WHERE pid = :root
                 UNION ALL
                 SELECT p.id FROM tl_page p JOIN tree ON p.pid = tree.id
             )
             SELECT p.id
               FROM tl_page p
               JOIN tree ON tree.id = p.id
               JOIN tl_article a ON a.pid = p.id AND a.published = 1
               JOIN tl_content c ON c.pid = a.id AND c.ptable = 'tl_article' AND c.invisible = 0 AND c.type = 'consho_product_list'
              WHERE p.published = 1 AND p.type = 'regular'
              ORDER BY p.id
              LIMIT 1",
            ['root' => $rootId],
        );

        return false === $id ? null : (int) $id;
    }
}

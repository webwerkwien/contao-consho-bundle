<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Contao\CoreBundle\Routing\Page\PageRegistry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;

/**
 * A start point whose first page is a product page (acceptance GARAN on c5,
 * `/shop2/` → 500; Michael, 2026-10-09: "ja").
 *
 * Contao's RootPageController forwards a start point to its first published
 * page — PageModel::findFirstPublishedByPid(): sorted, not a root, not a type
 * without an address. A product page has an address, but only with a product
 * in it; without one the router throws RouteParametersException, and the
 * start point answers 500. Contao's own reader pages (news, events) do the
 * same; it is the page tree, not Consho.
 *
 * Consho does not reroute the forward — that would be a change to the core's
 * controller. It says where to fix it: another page first under the start
 * point. A warning: the domain itself is broken, and nothing in the back end
 * shows it.
 */
final class StartPointProductPageIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        // The types Contao skips (no address). Optional for the tests.
        #[Autowire(service: 'contao.routing.page_registry')]
        private readonly PageRegistry|null $pages = null,
    ) {
    }

    public function issues(): array
    {
        $roots = $this->connection->fetchAllAssociative("SELECT id, title, consho_shop FROM tl_page WHERE type = 'root' AND published = 1 ORDER BY sorting, id");
        $skipped = ['root', ...($this->pages?->getUnroutableTypes() ?? [])];
        // Floored to the minute, like Contao (PageModel, Date::floorToMinute()).
        $now = (string) (intdiv(time(), 60) * 60);
        $served = $this->rootsWithIndexPage($skipped, $now);
        $issues = [];

        foreach ($roots as $root) {
            // A page with the alias "index" or "/" answers for the start
            // point's address itself (RouteProvider: "root" routes for it) —
            // then nothing is forwarded (c5: "Contao Official Demo" has one).
            if (isset($served[(int) $root['id']])) {
                continue;
            }

            // The same conditions as findFirstPublishedByPid(), outside the preview.
            $first = $this->connection->fetchAssociative(
                "SELECT title, type FROM tl_page
                  WHERE pid = ? AND type NOT IN (?) AND published = 1
                    AND (start = '' OR start <= ?) AND (stop = '' OR stop > ?)
                  ORDER BY sorting
                  LIMIT 1",
                [(int) $root['id'], $skipped, $now, $now],
                [ParameterType::INTEGER, ArrayParameterType::STRING],
            );

            if (false === $first || ProductPageController::TYPE !== $first['type']) {
                continue;
            }

            $issues[] = new ConfigurationIssue(ConfigurationFinding::StartPointForwardsToProductPage, [
                'root' => (string) $root['title'],
                'page' => (string) $first['title'],
            ], (int) ($root['consho_shop'] ?? 0));
        }

        return $issues;
    }

    /**
     * The start points with a routable "index" or "/" page anywhere below
     * them — walked up through the tree, which Contao does not store.
     *
     * Routable as Contao sees it: public, i.e. published and inside start and
     * stop (PublishedFilter), and of a type with an address (RouteProvider).
     * An expired index page loses its route, and the forward is back.
     *
     * @param list<string> $skipped
     *
     * @return array<int, true> by root id
     */
    private function rootsWithIndexPage(array $skipped, string $now): array
    {
        $index = array_map(intval(...), $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_page
              WHERE alias IN ('index', '/') AND type NOT IN (?) AND published = 1
                AND (start = '' OR start <= ?) AND (stop = '' OR stop > ?)",
            [$skipped, $now, $now],
            [ArrayParameterType::STRING],
        ));

        if ([] === $index) {
            return [];
        }

        $parents = $this->connection->fetchAllKeyValue('SELECT id, pid FROM tl_page');
        $roots = [];

        foreach ($index as $id) {
            // Bounded: a broken tree with a cycle must not hang the back end.
            for ($depth = 0, $current = $id; $depth < 100 && (int) ($parents[$current] ?? 0) > 0; ++$depth) {
                $current = (int) $parents[$current];
            }

            $roots[$current] = true;
        }

        return $roots;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DC_Table;
use Contao\PageModel;
use Doctrine\DBAL\Connection;

/**
 * Checks the path segment of a facet landing page before it is stored.
 *
 * A landing page answers at /<listing page alias>/<alias>.html — the auto_item
 * of its listing page (docs/block-b-katalog-frontend.md, 4.5). Three things can
 * make that address wrong without anything failing on save:
 *
 * 1. Form. Contao's rgxp "alias" allows [\w.-], so capitals, underscores and
 *    dots pass. Filter addresses allow lowercase letters, digits and hyphens
 *    only (FilterStateCodec::ALIAS); a landing page is one of them.
 * 2. Duplicate on the same page. The unique index pid,alias would catch it —
 *    with a 500 and a raw SQL message.
 * 3. A subpage in the way. With folder URLs a subpage "kraeuter/foo" has two
 *    path segments, the category one; at equal priority the longer route wins
 *    (AbstractPageRouteProvider::compareRoutes()). The landing page would be
 *    stored and never reachable.
 *
 * ⚠️ Case 3 is checked only here, on save. A subpage created AFTER the landing
 * page hides it just the same, and nothing reports that.
 *
 * Reads through DC_Table::getActiveRecord(), like FacetStateListener: on a new
 * record the page the editor just picked exists only in the submitted values.
 * That works because pid stands before alias in the palette.
 */
class FacetAliasListener
{
    private const FORM = '/^[a-z0-9-]+$/';

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_facet_page', target: 'fields.alias.save')]
    public function __invoke(mixed $value, DC_Table $dc): string
    {
        $alias = (string) $value;

        if (1 !== preg_match(self::FORM, $alias)) {
            throw new \RuntimeException(
                \sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetAliasForm'], $alias),
            );
        }

        $pageId = (int) ($dc->getActiveRecord()['pid'] ?? 0);

        // No page chosen yet: pid is mandatory and reports that itself.
        if ($pageId < 1) {
            return $alias;
        }

        $existing = $this->connection->fetchOne(
            'SELECT id FROM tl_consho_facet_page WHERE pid = :page AND alias = :alias AND id != :id',
            ['page' => $pageId, 'alias' => $alias, 'id' => (int) $dc->id],
        );

        if (false !== $existing) {
            throw new \RuntimeException(
                \sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetAliasDuplicate'], $alias, $existing),
            );
        }

        $this->refuseShadowingPage($pageId, $alias);

        return $alias;
    }

    private function refuseShadowingPage(int $pageId, string $alias): void
    {
        $pages = $this->framework->getAdapter(PageModel::class);
        $listing = $pages->findWithDetails($pageId);

        // A page that cannot be loaded is FacetStateListener's to report.
        if (!$listing instanceof PageModel) {
            return;
        }

        $path = $listing->alias.'/'.$alias;

        $candidates = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_page WHERE alias = :path AND id != :page',
            ['path' => $path, 'page' => $pageId],
        );

        foreach ($candidates as $candidateId) {
            $candidate = $pages->findWithDetails((int) $candidateId);

            /*
             * The same alias is the same address only under the same
             * language prefix and suffix — and, mostly, the same domain. A
             * start point for another domain or language has routes of its
             * own and cannot shadow this one.
             *
             * ⚠️ Except when THIS listing's own domain is empty. An empty dns
             * answers on every domain (PageModel::loadDetails() inherits it
             * down), so its route carries no host. Per
             * AbstractPageRouteProvider::compareRoutes() a route WITH a host
             * beats one without, before the segment count is even compared —
             * so a candidate on any specific domain would win there and hide
             * this landing page on that domain. Only a listing that itself
             * has a specific domain can rule out candidates on another one:
             * its own route already carries a host and wins regardless of
             * whether the candidate's does.
             */
            if (
                !$candidate instanceof PageModel
                || ($candidate->domain !== $listing->domain && '' !== $listing->domain)
                || $candidate->urlPrefix !== $listing->urlPrefix
                || $candidate->urlSuffix !== $listing->urlSuffix
            ) {
                continue;
            }

            throw new \RuntimeException(
                \sprintf(
                    $GLOBALS['TL_LANG']['CONSHO']['errors']['facetAliasShadowed'],
                    $candidate->title,
                    $candidate->id,
                    '/'.($listing->urlPrefix ? $listing->urlPrefix.'/' : '').$path.$listing->urlSuffix,
                ),
            );
        }
    }
}

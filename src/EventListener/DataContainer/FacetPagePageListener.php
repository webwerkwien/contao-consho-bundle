<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * Offers the listing pages a facet landing page can live below.
 *
 * Only pages that carry a visible listing following the address, and only in a
 * start point that has a shop: below any other page the landing page would
 * answer 404 or show nothing.
 *
 * Why no page tree picker: pageTree can be limited to root nodes, not to "pages
 * that carry a listing". A picker offering every page would move the check into
 * an error message after saving. The list stays short — these are category
 * pages. FacetStateListener checks the same condition again on save, because a
 * record may point at a page that has lost its listing since.
 */
class FacetPagePageListener
{
    public function __construct(
        private readonly ListingPageRepository $listings,
        private readonly ContaoFramework $framework,
        private readonly ShopResolver $shops,
    ) {
    }

    /**
     * @return array<string, array<int, string>>
     */
    #[AsCallback(table: 'tl_consho_facet_page', target: 'fields.pid.options')]
    public function __invoke(): array
    {
        $pages = $this->framework->getAdapter(PageModel::class);
        $options = [];

        foreach ($this->listings->pagesWithList(time()) as $pageId => $title) {
            $page = $pages->findWithDetails($pageId);

            if (!$page instanceof PageModel || null === $this->shops->resolveForPage($page)) {
                continue;
            }

            // Grouped by start point: the same category exists once per
            // language, and the editor has to see which one they are writing
            // the title for.
            $options[\sprintf('%s (%s)', $page->rootTitle, $page->language)][$pageId] = $title;
        }

        // Groups by start point, not by whichever page title came first.
        uksort($options, strnatcasecmp(...));

        return $options;
    }
}

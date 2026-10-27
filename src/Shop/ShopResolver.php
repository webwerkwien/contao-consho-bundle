<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shop;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;

/**
 * Resolves a page to its shop configuration.
 *
 * n root pages : 1 shop. A multilingual site is several root pages in Contao,
 * but one shop — see Block A, section 4.
 */
final class ShopResolver
{
    public function __construct(private readonly ContaoFramework $framework)
    {
    }

    public function resolveForPage(PageModel $page): ?ShopModel
    {
        $root = $this->rootOf($page);

        return null === $root ? null : $this->resolveById($this->shopIdOfPage($root));
    }

    /**
     * The root page of a page — which is where every site-wide Consho setting
     * lives (the shop, the basket page).
     *
     * Null where the page has no start point to give: either there is none, or
     * the tree above this page has not been walked and it therefore cannot say.
     * This method does not walk it — see below.
     *
     * Public because more than the shop hangs off the root: ShopPageUrls asks
     * the same question for `consho_cartPage`, and a second walk up the tree
     * would be the same rule written twice.
     */
    public function rootOf(PageModel $page): PageModel|null
    {
        /*
         * `type`, not `rootId`: `type` is a real column of tl_page and is
         * therefore true of a page straight out of findById(), while `rootId`
         * is a property PageModel only fills in while walking up the tree.
         * Contao's own tree logic turns on exactly this value — see
         * PageModel::loadDetails(), PageModel.php:950 and :1077.
         *
         * The page is its own root: hand it back instead of loading the same
         * record again.
         */
        if ('root' === $page->type) {
            return $page;
        }

        $rootId = (int) $page->rootId;

        /*
         * Null rather than the page itself. What stood here was
         * `(int) $page->rootId ?: $pageId` — "no rootId? then you are your own
         * start point", which is true of a root page and of nothing else. An
         * unwalked sub page was handed back as its own start point, carried no
         * `consho_shop`, and "add to basket" answered 400 from every page a
         * visitor buys from. Saying "I do not know" lets the
         * caller answer "no shop here" instead of resolving the wrong record.
         *
         * Deliberately NOT loadDetails() here: it throws NoRootPageFoundException
         * for an orphaned page in the front end (PageModel.php:1087) and calls
         * preventSaving() on the way out, so "a page outside a shop" would
         * become a 500 and every caller would be left with a model it can no
         * longer save. Whoever wants the walk asks for it — currencyOfPage()
         * and AddToCartController do.
         */
        if ($rootId < 1) {
            return null;
        }

        $root = $this->framework->getAdapter(PageModel::class)->findByPk($rootId);

        return $root instanceof PageModel ? $root : null;
    }

    /**
     * `consho_shop` is added to tl_page by Consho's own DCA, so it is not
     * declared on Contao's PageModel. Reading it from the row array says that
     * out loud and keeps static analysis meaningful — silencing the warning
     * here would also hide a genuine typo in the column name.
     */
    public function shopIdOfPage(PageModel $page): int
    {
        return (int) ($page->row()['consho_shop'] ?? 0);
    }

    /**
     * The currency that applies on a page — the one picked on its start point.
     *
     * The currency belongs to the audience and therefore to the start point,
     * the home country to the shop that writes the invoice: an Austrian shop
     * selling over /en/ in pounds is the market pair (GBP, AT). See
     * Block A, section 4.0.
     *
     * An empty string where the start point is gone or carries no currency.
     * Callers pass that on unchanged rather than falling back to the euro: no
     * price row matches it, so such a page shows no prices at all instead of
     * prices in a currency nobody chose. The two basket elements guard on it
     * before they render — they format a total even when empty, and an empty
     * code makes ICU print its placeholder ("0,00 XXX").
     *
     * ⚠️ It can also throw, and only in the front end: loadDetails() answers a
     * page that belongs to no root page with NoRootPageFoundException
     * (PageModel.php:1087), which happens before this method has anything to
     * return. The empty string for a missing start point is therefore the back
     * end's and the command line's answer, not the visitor's — a visitor gets
     * Contao's own 404 for that page, which is the right one.
     *
     * @throws \Contao\CoreBundle\Exception\NoRootPageFoundException in the front end, for a page below no root page
     */
    public function currencyOfPage(PageModel $page): string
    {
        /*
         * loadDetails() first, and this is not optional: `rootId` is no column
         * of tl_page but a property PageModel fills in while walking up the
         * tree. Without it rootOf() hands back the sub page itself — and the
         * currency sits on the start point, so the answer would be empty on
         * every page a visitor actually buys from. That is the shape of an
         * earlier bug, where the same missing walk made
         * "add to basket" answer 400 below the start point.
         *
         * It costs nothing where the front end index has already done it:
         * PageModel guards on its own flag and returns straight away.
         */
        $page->loadDetails();

        $root = $this->rootOf($page);

        // Read from the row array for the same reason shopIdOfPage() does:
        // `consho_currency` is added to tl_page by Consho's own DCA and is not
        // declared on Contao's PageModel.
        return null === $root ? '' : (string) ($root->row()['consho_currency'] ?? '');
    }

    public function resolveById(int $shopId): ?ShopModel
    {
        // Guard before touching the framework: an installation without a shop
        // must not query for one on every request.
        if ($shopId < 1) {
            return null;
        }

        $shop = $this->framework->getAdapter(ShopModel::class)->findByPk($shopId);

        return $shop instanceof ShopModel ? $shop : null;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * "Back to the page" is configured, but no mini basket exists anywhere
 * (spec 11.3).
 *
 * A hint, never a warning: the shop works, but adding something looks to the
 * visitor like nothing happened. Hints do not block the checkout (11.2).
 *
 * ⚠️ Deliberately NOT scoped to a page or an article: since Contao 5.7 a
 * layout section's content elements are tl_content rows with
 * ptable = 'tl_theme' instead of pid pointing at an article (ModuleWizard,
 * contao/dca/tl_content.php) — exactly where a mini basket is meant to
 * live, so it shows up on every page that uses the layout. A query that
 * followed the usual "join via the article" pattern would miss precisely
 * the placement this element exists for. So the query asks only
 * type = 'consho_mini_cart', nothing about ptable or pid.
 *
 * The question is asked site-wide, not per page: a mini basket in a layout
 * section appears on every page that uses that layout, and chasing which
 * page shows which section would be a query per page on every back end
 * request.
 */
final class DbalMiniCartIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        /*
         * The shop titles, not a count: they go into the issue's context, and
         * the context is what the fingerprint is built from. Without it every
         * possible case of this finding shares one fingerprint — one click on
         * "acknowledge" would silence it forever, including for a shop set up
         * months later. ConfigurationIssue says so itself: "A fingerprint over
         * the finding alone would turn one click into a permanent blindfold."
         *
         * Still one query, still no row loop over shops.
         */
        $shops = $this->connection->fetchFirstColumn(
            "SELECT title FROM tl_consho_shop WHERE afterAddToCart = 'page' ORDER BY title",
        );

        if ([] === $shops) {
            return [];
        }

        $miniCarts = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_content WHERE type = 'consho_mini_cart' AND invisible = 0",
        );

        if ($miniCarts > 0) {
            return [];
        }

        return [new ConfigurationIssue(
            ConfigurationFinding::MiniCartMissing,
            ['shops' => implode(', ', array_map(strval(...), $shops))],
        )];
    }
}

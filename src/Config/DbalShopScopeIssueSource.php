<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Shop\CurrencySelection;

/**
 * How far a shop reaches: the currency of its start pages, and the fact that
 * all shops of an installation share one pool of members (C-1c, spec 4.0).
 *
 * Since C-1c the currency comes from the START PAGE and the home country from
 * the shop. The shop only says which currencies it CAN accept. Two states can
 * therefore exist that no mask would produce, and neither shows up anywhere
 * else:
 *
 * | RootWithoutCurrency | a start page with a shop but no currency — it can show no price at all, and a price without a currency is not a statement |
 * | RootCurrencyNotSold | a start page selling in a currency the shop does not accept (any more) |
 *
 * The second one arises SILENTLY, and that is why it is worth a query on every
 * back end start page: shop ['EUR','CHF'], start page CHF, the operator drops
 * CHF at the shop. The shop then has exactly one currency left, so
 * RootCurrencyListener::adjustPalette() hides the field — from then on save()
 * never runs again, and onBeforeSubmit() only on the next save WITH A CHANGE to
 * that very page. Until then the start page sells in money the shop does not
 * carry: no shipping amount, no payment method, nothing to invoice with.
 * RootWithoutCurrency does not catch it — the currency is not empty, it is
 * wrong.
 *
 * The third finding has nothing to do with the currency and sits here because
 * this is the class about a shop's reach:
 *
 * | MultipleShopsShareMembers | from the second shop on — a HINT, never a warning |
 *
 * ── Read past ShopCurrencies, on purpose ──────────────────────────────────
 *
 * The shop's currencies are parsed here with CurrencySelection rather than
 * asked of ShopCurrencies, although that service answers exactly this
 * question. The reason is the JOIN: a start page pointing at a DELETED shop
 * must drop out of this check entirely — there is no shop that could accept or
 * refuse anything, and "the shop of this start page" would name one that does
 * not exist (the same distinction RootCurrencyListener::save() draws with
 * titleOf()). An INNER JOIN says that exactly and in the same query that
 * fetches the title for the message, where ShopCurrencies would have to infer
 * it from an empty title. CurrencySelection is the shared parser both readers
 * stand on, so the two cannot disagree about what the blob contains.
 *
 * ⚠️ Runs on every call of the back end start page (11.3): two counting
 * queries, one over the start pages, one over the shops. Both tables hold a
 * handful of rows.
 */
final class DbalShopScopeIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $issues = [];

        /*
         * ⚠️ The `p.id` in the ORDER BY only ties the order of two start
         * points of the SAME title, and no test here can turn red on it:
         * SQLite's reverse_unordered_selects touches queries without an ORDER
         * BY only, so the tie comes out in insertion order either way. It stays on the argument alone — MySQL may
         * answer a tie in any order, and a findings list that changes its
         * sequence between two calls of the start page reads as if something
         * had changed.
         */
        $roots = $this->connection->fetchAllAssociative(
            "SELECT p.id, p.title, p.consho_currency AS currency, s.id AS shopId, s.title AS shop, s.currencies
               FROM tl_page p
               INNER JOIN tl_consho_shop s ON s.id = p.consho_shop
              WHERE p.type = 'root'
              ORDER BY p.title, p.id",
        );

        foreach ($roots as $root) {
            // Upper-cased and trimmed like RootCurrencyListener::save() does
            // before storing: the migration of task 3 writes this column
            // directly, never through a mask, so a lower-case "eur" out of the
            // old shop column is the same currency and no finding.
            $currency = strtoupper(trim((string) $root['currency']));
            $page = \sprintf('%s (ID %d)', (string) $root['title'], (int) $root['id']);

            if ('' === $currency) {
                // With the shop: since block I these two stop "ready" — of
                // this shop, not of every shop (I).
                $issues[] = new ConfigurationIssue(
                    ConfigurationFinding::RootWithoutCurrency,
                    ['page' => $page, 'shop' => (string) $root['shop']],
                    (int) $root['shopId'],
                );

                continue;
            }

            if (\in_array($currency, CurrencySelection::fromBlob($root['currencies'])->valid, true)) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::RootCurrencyNotSold,
                ['page' => $page, 'currency' => $currency, 'shop' => (string) $root['shop']],
                (int) $root['shopId'],
            );
        }

        /*
         * The titles rather than the count alone, and for the same reason as in
         * DbalMiniCartIssueSource: the context is what the fingerprint is built
         * from. An operator who took note of two shops has said nothing about
         * the third, and with a constant context one click would silence the
         * finding for every shop added later.
         */
        $shops = $this->connection->fetchFirstColumn('SELECT title FROM tl_consho_shop ORDER BY title');

        if (\count($shops) > 1) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::MultipleShopsShareMembers,
                ['shops' => implode(', ', array_map(strval(...), $shops))],
            );
        }

        return $issues;
    }
}

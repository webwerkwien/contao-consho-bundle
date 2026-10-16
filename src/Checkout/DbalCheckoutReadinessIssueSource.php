<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationCheck;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Shipping\DbalShippingMethodRepository;

/**
 * What has to be in place before a shop can take an order (11.2, 11.3).
 *
 * ── Why these are warnings and what they block ────────────────────────────
 *
 * All four block the CHECKOUT of the shop they belong to, and nothing else.
 * The catalogue still works, the basket still fills — the visitor is stopped
 * at the point where an order would be legally or technically impossible, and
 * they are stopped BEFORE typing an address rather than after.
 *
 * ── Only counting queries ─────────────────────────────────────────────────
 *
 * getSystemMessages() runs several times per back end page (block B, section
 * 10). Every query here answers a number or a short list of shop titles, never
 * a join across the catalogue.
 *
 * ── What is deliberately NOT here ─────────────────────────────────────────
 *
 * The terms, withdrawal and privacy pages, the invoice sender and the number
 * ranges. They arrived with C-6 and live in DbalLegalReadinessIssueSource,
 * beside this one rather than inside it: this class asks whether the checkout
 * can RUN, that one whether it may. They block the same way and carry the same
 * tag, but they are cleared by different people.
 */
#[AutoconfigureTag('consho.checkout_readiness_source')]
final class DbalCheckoutReadinessIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigurationCheck $check,
    ) {
    }

    public function issues(): array
    {
        return [
            ...$this->pageIssues(),
            ...$this->shopPageIssues(),
            ...$this->methodIssues(),
            ...$this->shopIssues(),
        ];
    }

    /**
     * Mail-Transport und Heimatland (11.3). Sie standen bis zum zweiten
     * Review vom 2026-09-22 in DbalConfigurationIssueSource — zusammen mit
     * der ganzen Steuerdiagnose, die dann bei jedem Checkout-Aufruf im
     * Frontend mitlief, eine Abfrage je Steuerklasse je Shop. Hier kosten
     * sie eine Abfrage, die es ohnehin gibt.
     *
     * @return list<ConfigurationIssue>
     */
    private function shopIssues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, mailTransport, country FROM tl_consho_shop ORDER BY id') as $row) {
            $issues = [
                ...$issues,
                ...$this->check->mailTransport((int) $row['mailTransport'], (string) $row['title'], (int) $row['id']),
                ...$this->check->homeCountry((string) $row['country'], (string) $row['title'], (int) $row['id']),
            ];
        }

        return $issues;
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function pageIssues(): array
    {
        $issues = [];

        /*
         * A start point that names a shop but no checkout page. Read from the
         * root pages rather than from the shops: a shop with no start point at
         * all is a different situation, already reported as CurrencyMissing /
         * RootWithoutCurrency, and saying it twice trains people to skim.
         *
         * 🔴 PUBLISHED ONLY. Found on c5 on 2026-09-22, in the acceptance of
         * C-5 itself: two unpublished test start points of shop 1 had no
         * checkout page, so this finding fired, and through the readiness
         * check it BLOCKED THE CHECKOUT of a shop whose live start point was
         * set up correctly. An unpublished start point serves nobody — a
         * finding about it is noise, and a finding that stops a working shop
         * is worse than none.
         *
         * `start`/`stop` are in as well, for the same reason C-4 read
         * MemberGroupModel::findAllActive(): a page outside its window is as
         * unreachable as an unpublished one.
         */
        $without = $this->connection->fetchAllAssociative(
            "SELECT p.title, p.consho_shop AS shop
             FROM tl_page p
             WHERE p.type IN ('root', 'rootfallback')
               AND p.consho_shop > 0
               AND p.published = 1
               AND (p.start = '' OR p.start <= :now)
               AND (p.stop = '' OR p.stop > :now)
               AND (p.consho_checkoutPage = 0 OR p.consho_checkoutPage IS NULL)
             ORDER BY p.id",
            ['now' => $this->minute()],
        );

        /*
         * One finding PER SHOP, not one for all of them: the readiness check
         * blocks the shop a finding belongs to (11.2), so a finding that
         * covered three shops at once would block all three.
         */
        foreach ($this->byShop($without) as $shopId => $titles) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::CheckoutPageMissing,
                ['count' => (string) \count($titles), 'pages' => implode(', ', $titles)],
                $shopId,
            );
        }

        /*
         * A checkout page with no checkout element on it.
         *
         * ⚠️ TWO placements, not one. The obvious one is an element in an
         * article of that page. The other is an element nested in a CONTENT
         * element — an element group, an accordion — which carries
         * `ptable = 'tl_content'` and whose `pid` is the outer element. The
         * review of 2026-09-22 caught the first version claiming to handle
         * "anything nested" while only joining tl_article: a shop with its
         * checkout inside an accordion would have been reported as empty and,
         * through the readiness check, BLOCKED.
         *
         * One level of nesting, not arbitrarily many. Deeper is possible and
         * rare, and a recursive query on every back end page is the more
         * expensive mistake — a finding that names one page too many is
         * cheaper than one that blocks a working shop, which is why the
         * sentence says where to look rather than promising completeness.
         */
        $pages = $this->connection->fetchAllAssociative(
            "SELECT target.title, root.consho_shop AS shop
             FROM tl_page root
             JOIN tl_page target ON target.id = root.consho_checkoutPage
             WHERE root.type IN ('root', 'rootfallback')
               AND root.consho_shop > 0
               AND root.published = 1
               AND (root.start = '' OR root.start <= :now)
               AND (root.stop = '' OR root.stop > :now)
               AND target.published = 1
               AND NOT EXISTS (
                   SELECT 1 FROM tl_content c
                   JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article'
                   WHERE a.pid = target.id AND c.type = 'consho_checkout'
                     AND ".self::visible('c', 'invisible = 0').'
                     AND '.self::visible('a', 'published = 1')."
               )
               AND NOT EXISTS (
                   SELECT 1 FROM tl_content nested
                   JOIN tl_content outer_element ON outer_element.id = nested.pid AND nested.ptable = 'tl_content'
                   JOIN tl_article a2 ON a2.id = outer_element.pid AND outer_element.ptable = 'tl_article'
                   WHERE a2.pid = target.id
                     AND nested.type = 'consho_checkout'
                     AND ".self::visible('nested', 'invisible = 0').'
                     AND '.self::visible('outer_element', 'invisible = 0').'
                     AND '.self::visible('a2', 'published = 1').'
               )
             ORDER BY target.id',
            ['now' => $this->minute()],
        );

        foreach ($this->byShop($pages) as $shopId => $titles) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::CheckoutElementMissing,
                ['count' => (string) \count($titles), 'pages' => implode(', ', $titles)],
                $shopId,
            );
        }

        return $issues;
    }

    /**
     * The other two shop pages: basket and order (11.3, first row).
     *
     * They arrived with C-6, which first put them in the legal source next
     * door. The review of 2026-09-22 moved them here, where the checkout page
     * already lives: a missing page is a site that is not finished, not a shop
     * that may not sell.
     *
     * ⚠️ The basket page is checked for its ELEMENT as well — that is the half
     * of 11.3's first row ("oder Seite ohne passendes Element") that C-6
     * quietly dropped from its plan. A set but empty basket page leaves the
     * visitor staring at nothing after adding an article.
     *
     * The order page is checked for its element since C-10, which brought the
     * element (Michael, 2026-09-25, E5). Before that an empty page was all an
     * operator could honestly produce.
     *
     * @return list<ConfigurationIssue>
     */
    private function shopPageIssues(): array
    {
        $issues = [];

        foreach ([
            [ConfigurationFinding::CartPageMissing, 'consho_cartPage'],
            [ConfigurationFinding::OrderPageMissing, 'consho_orderPage'],
            // E-6 (V5): a note, and not in CheckoutReadiness::BLOCKING.
            [ConfigurationFinding::ShippingCostsPageMissing, 'consho_shippingCostsPage'],
        ] as [$finding, $field]) {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT p.id, p.title, p.consho_shop AS shop
                       FROM tl_page p
                      WHERE %1$s
                        AND (p.%2$s = 0 OR p.%2$s IS NULL)
                      ORDER BY p.id',
                    self::liveRoot('p'),
                    $field,
                ),
                ['now' => $this->minute()],
            );

            foreach ($this->byShop($rows) as $shopId => $titles) {
                $issues[] = new ConfigurationIssue(
                    $finding,
                    ['count' => (string) \count($titles), 'pages' => implode(', ', $titles)],
                    $shopId,
                );
            }
        }

        // The basket page without a basket element, and since C-10 the order
        // page without an order element. Same two placements as the checkout
        // page above — article and one level of nesting.
        foreach ([
            [ConfigurationFinding::CartElementMissing, 'consho_cartPage', 'consho_cart'],
            [ConfigurationFinding::OrderElementMissing, 'consho_orderPage', 'consho_order'],
            [ConfigurationFinding::ShippingCostsElementMissing, 'consho_shippingCostsPage', 'consho_shipping_costs'],
        ] as [$finding, $field, $type]) {
            $empty = $this->connection->fetchAllAssociative(
                \sprintf(
                    "SELECT target.id, target.title, root.consho_shop AS shop
                       FROM tl_page root
                       JOIN tl_page target ON target.id = root.%2\$s
                      WHERE %1\$s
                        AND target.published = 1
                        AND NOT EXISTS (
                            SELECT 1 FROM tl_content c
                            JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article'
                            WHERE a.pid = target.id AND c.type = :type
                              AND %3\$s
                              AND %4\$s
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM tl_content nested
                            JOIN tl_content outer_element ON outer_element.id = nested.pid AND nested.ptable = 'tl_content'
                            JOIN tl_article a2 ON a2.id = outer_element.pid AND outer_element.ptable = 'tl_article'
                            WHERE a2.pid = target.id
                              AND nested.type = :type
                              AND %5\$s
                              AND %6\$s
                              AND %7\$s
                        )
                      ORDER BY target.id",
                    self::liveRoot('root'),
                    $field,
                    self::visible('c', 'invisible = 0'),
                    self::visible('a', 'published = 1'),
                    self::visible('nested', 'invisible = 0'),
                    self::visible('outer_element', 'invisible = 0'),
                    self::visible('a2', 'published = 1'),
                ),
                ['now' => $this->minute(), 'type' => $type],
            );

            foreach ($this->byShop($empty) as $shopId => $titles) {
                $issues[] = new ConfigurationIssue(
                    $finding,
                    ['count' => (string) \count($titles), 'pages' => implode(', ', $titles)],
                    $shopId,
                );
            }
        }

        return $issues;
    }

    /**
     * A root page that actually serves somebody. Written once — five queries
     * use it, and five copies would be five chances to forget `published`
     * again (the C-5 acceptance).
     */
    /**
     * An article or element that renders now: its flag, and inside its
     * start/stop — as in DbalLegalReadinessIssueSource. Found at the deploy of
     * C-10: the order page's article was unpublished, and the finding said
     * nothing, because only `invisible` was asked.
     */
    private static function visible(string $alias, string $flag): string
    {
        return \sprintf(
            "%1\$s.%2\$s AND (%1\$s.start = '' OR %1\$s.start <= :now) AND (%1\$s.stop = '' OR %1\$s.stop > :now)",
            $alias,
            $flag,
        );
    }

    private static function liveRoot(string $alias): string
    {
        return \sprintf(
            "%1\$s.type IN ('root', 'rootfallback')
             AND %1\$s.consho_shop > 0
             AND %1\$s.published = 1
             AND (%1\$s.start = '' OR %1\$s.start <= :now)
             AND (%1\$s.stop = '' OR %1\$s.stop > :now)",
            $alias,
        );
    }

    /**
     * Die volle Minute, wie Contao sie für `start`/`stop` vergleicht. Eine
     * Sekunde wäre genauer und würde die Abfrage bei jedem Aufruf neu
     * ausführen lassen — Contao selbst rundet hier ebenso.
     */
    private function minute(): string
    {
        return (string) (int) (floor(time() / 60) * 60);
    }

    /**
     * Titel je Shop, in der Reihenfolge, in der die Abfrage sie lieferte.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, list<string>>
     */
    private function byShop(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['shop']][] = (string) $row['title'];
        }

        return $grouped;
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function methodIssues(): array
    {
        $issues = [];

        // A list of tuples, not an enum-keyed map: PHP array keys are int or
        // string, and an enum case is neither.
        foreach ([
            [ConfigurationFinding::ShippingMethodMissing, 'shippingMethods', 'tl_consho_shipping_method', DbalShippingMethodRepository::offerable()],
            [ConfigurationFinding::PaymentMethodMissing, 'paymentMethods', 'tl_consho_payment_method', "published = 1 AND type != ''"],
        ] as [$finding, $column, $table, $condition]) {
            foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT id, title, %s AS selected FROM tl_consho_shop ORDER BY id', $column)) as $row) {
                $ids = MethodSettings::selectedIds($row['selected'] ?? null);

                /*
                 * Selected AND offerable. A shop that ticked three methods and
                 * then unpublished all three is in exactly the state this
                 * finding is about, and a plain "nothing ticked" check would
                 * call that configured.
                 */
                if ([] !== $ids && 0 < (int) $this->connection->fetchOne(
                    \sprintf('SELECT COUNT(*) FROM %s WHERE %s AND id IN (?)', $table, $condition),
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::INTEGER],
                )) {
                    continue;
                }

                $issues[] = new ConfigurationIssue(
                    $finding,
                    ['shops' => (string) $row['title']],
                    (int) $row['id'],
                );
            }
        }

        return $issues;
    }
}

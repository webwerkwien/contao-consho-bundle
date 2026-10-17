<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\SetupIssueSource;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;

/**
 * What the law needs in place before a shop can take an order (C-6, 11.3).
 *
 * A source of its own beside DbalCheckoutReadinessIssueSource, not more cases
 * inside it: that one asks whether the checkout can RUN, this one whether it
 * may. They block the same way and are tagged the same way, but they are
 * cleared by different people — the first by whoever builds the site, the
 * second by whoever answers for its terms.
 *
 * The basket and order pages started out here and moved next door after the
 * review of 2026-09-22: a missing page is a site that is not finished, not a
 * shop that may not sell. The line has to hold, or the split is decoration.
 *
 * ── Only counting queries, again ──────────────────────────────────────────
 *
 * Ten queries for an entire installation — two for the linked pages, one for
 * the warranty file (C-9), two for the legal texts, one for the withdrawal
 * function (D-2), one for the sender, three for the number ranges — each one
 * a short list, none of them a join across the catalogue.
 *
 * ⚠️ And they run in the FRONT end too, on every checkout page view and every
 * POST, because CheckoutReadiness reads this source (11.2). That is the budget
 * to keep in mind before adding an eleventh: "counting queries only" was written
 * for a back end page that renders a few times, and this is stricter.
 *
 * ── Published only, same as C-5 ───────────────────────────────────────────
 *
 * Every query here filters root pages the way the acceptance of C-5 taught:
 * unpublished or outside its start/stop window serves nobody, and a finding
 * about it would block a shop that works. The rule is applied to the TARGET
 * pages too — an unpublished terms page is a missing terms page.
 */
#[AutoconfigureTag('consho.checkout_readiness_source')]
final class DbalLegalReadinessIssueSource implements SetupIssueSource
{
    /**
     * The invoice sender fields that have to be filled in, and the order they
     * are named in. `invoiceLegalForm` is deliberately absent — in Austria and
     * Germany it is usually part of the registered name already.
     */
    private const SENDER_FIELDS = ['invoiceCompany', 'invoiceStreet', 'invoicePostal', 'invoiceCity', 'invoiceCountry'];

    private bool $forSetup = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentHandlers $handlers,
    ) {
    }

    public function issuesForSetup(): array
    {
        $copy = clone $this;
        $copy->forSetup = true;

        return $copy->issues();
    }

    public function issues(): array
    {
        return [
            ...$this->linkedPageIssues(ConfigurationFinding::PrivacyPageMissing, 'consho_privacyPage'),
            ...$this->linkedPageIssues(ConfigurationFinding::WarrantyNoticeMissing, 'consho_warrantyPage'),
            ...$this->warrantyFileIssues(),
            ...$this->legalTextIssues(),
            ...$this->withdrawalFunctionIssues(),
            ...$this->senderIssues(),
            ...$this->numberRangeIssues(),
        ];
    }

    /**
     * A page the checkout has to link to: unset, or set and not reachable.
     *
     * Both in one finding, unlike the legal texts below, and for a reason
     * rather than out of thrift: there is nothing to do differently. A page
     * that is unpublished and one that was never picked are the same missing
     * link, cleared by the same click.
     *
     * Two of them use this: the privacy statement (Art. 13 DSGVO) and the
     * harmonised guarantee notice (§ 4 Abs 1 Z 12 FAGG). Neither is frozen at
     * the buy click, so neither needs an element — a page is enough, and the
     * operator's own page at that.
     *
     * @return list<ConfigurationIssue>
     */
    private function linkedPageIssues(ConfigurationFinding $finding, string $field): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                "SELECT p.id, p.title, p.consho_shop AS shop
                   FROM tl_page p
              LEFT JOIN tl_page target ON target.id = p.%1\$s
                  WHERE %2\$s
                    AND (
                        p.%1\$s = 0
                        OR p.%1\$s IS NULL
                        OR target.id IS NULL
                        OR target.published != 1
                        OR (target.start != '' AND target.start > :now)
                        OR (target.stop != '' AND target.stop <= :now)
                    )
                  ORDER BY p.id",
                $field,
                $this->liveRoot('p'),
            ),
            ['now' => $this->minute()],
        );

        return $this->perShop($finding, $rows);
    }

    /**
     * The guarantee notice as a file for the confirmation (C-9): not chosen,
     * or chosen and no longer a file in tl_files.
     *
     * Its own finding beside the page, not the same one: a page and a file
     * are two fields and two jobs, and "notice missing" while the page is
     * plainly there would send the operator looking in the wrong place.
     *
     * One more counting query in the budget above — a join on tl_files by
     * uuid, which is indexed.
     *
     * @return list<ConfigurationIssue>
     */
    private function warrantyFileIssues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                "SELECT p.id, p.title, p.consho_shop AS shop
                   FROM tl_page p
              LEFT JOIN tl_files f ON f.uuid = p.consho_warrantyNotice AND f.type = 'file'
                  WHERE %s
                    AND f.id IS NULL
                  ORDER BY p.id",
                $this->liveRoot('p'),
            ),
            ['now' => $this->minute()],
        );

        return $this->perShop(ConfigurationFinding::WarrantyNoticeFileMissing, $rows);
    }

    /**
     * The terms and the withdrawal instruction (C17).
     *
     * ⚠️ TWO findings, not one, and that is the difference to the privacy page.
     * "Nothing picked" and "picked, but the buyer cannot read it" are different
     * jobs: the first is a field to fill in, the second is a page to publish,
     * an element to make visible or a protected page to open. An operator who
     * reads "terms missing" while the field plainly holds an element looks for
     * a bug instead of at the page.
     *
     * Unreachable means, all of it established by query: the element is gone,
     * is no longer of type "text", was set invisible or is outside ITS OWN
     * start/stop window; the article holding it is unpublished or outside its
     * window; the page is unpublished or outside its window; or — where the
     * shop lets guests order — the page is PROTECTED, because a buyer who has
     * to log in to read the terms has not been given them (§ 4 Abs. 1 FAGG).
     *
     * 🔴 The first version checked the PAGE alone and claimed all of it in its
     * message ("außerhalb seines Zeitfensters"). Both reviews of 2026-09-22
     * found it. A terms element in an unpublished article — the likeliest case
     * of the lot — left the checkout open while the link led to a page the
     * text does not appear on: § 4 FAGG not met, and the right of withdrawal
     * extended by twelve months (§ 12 FAGG).
     *
     * ⚠️ **The limit, stated rather than papered over:** inherited state is
     * NOT seen. A published terms page under an unpublished or protected
     * parent is reported as reachable. Contao resolves that by walking the
     * tree, and 11.3 allows counting queries only — this runs on every
     * checkout page view. The message therefore names what it checked and does
     * not promise completeness.
     *
     * @return list<ConfigurationIssue>
     */
    private function legalTextIssues(): array
    {
        $missing = [];
        $unreachable = [];
        $advised = [];

        foreach (LegalTextKind::cases() as $kind) {
            $field = $kind->field();

            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    "SELECT p.id, p.title, p.consho_shop AS shop, p.%1\$s AS element, c.id AS found,
                            s.termsRequired, s.country, s.withdrawalDays
                       FROM tl_page p
                  LEFT JOIN tl_consho_shop s ON s.id = p.consho_shop
                  LEFT JOIN tl_content c
                         ON c.id = p.%1\$s
                        AND c.ptable = 'tl_article'
                        AND c.type = 'text'
                        AND c.invisible = 0
                        AND (c.start = '' OR c.start <= :now)
                        AND (c.stop = '' OR c.stop > :now)
                  LEFT JOIN tl_article a
                         ON a.id = c.pid
                        AND a.published = 1
                        AND (a.start = '' OR a.start <= :now)
                        AND (a.stop = '' OR a.stop > :now)
                  LEFT JOIN tl_page host ON host.id = a.pid
                      WHERE %2\$s
                        AND (
                            p.%1\$s = 0
                            OR p.%1\$s IS NULL
                            OR c.id IS NULL
                            OR a.id IS NULL
                            OR host.id IS NULL
                            OR host.published != 1
                            OR (host.start != '' AND host.start > :now)
                            OR (host.stop != '' AND host.stop <= :now)
                            OR (host.protected = 1 AND s.guestCheckout = 1)
                        )
                      ORDER BY p.id",
                    $field,
                    $this->liveRoot('p'),
                ),
                ['now' => $this->minute()],
            );

            /*
             * ⚠️ `protected` counts only where guests may order.
             *
             * Found in the review of 2026-09-22, and it is the C-5 acceptance
             * mistake in a new dress: with guest checkout switched off every
             * buyer is signed in, so a protected terms page is perfectly
             * readable — and blocking that shop for ever would be a finding
             * against a shop that works, with no way to clear it but to
             * unprotect a page the operator protected on purpose.
             */
            foreach ($rows as $row) {
                $picked = (int) $row['element'] > 0;
                $entry = ['id' => $row['id'], 'title' => $row['title'], 'shop' => $row['shop'], 'kind' => $kind->value];

                /*
                 * D-2, E1 — only a REQUIRED text blocks (ShopLegalRequirements).
                 * Michael: "Sperre bei AGB ist schon falsch, man muss keine
                 * haben." Until then both texts blocked, always.
                 *
                 * A text that is not required but not right either is a
                 * notice: terms picked and unreachable (the operator meant to
                 * have some), a withdrawal instruction where the shop has a
                 * period but sits outside the EU/EEA. Nothing at all for terms
                 * nobody picked, or a shop without withdrawal.
                 */
                $required = \in_array($kind, ShopLegalRequirements::of((bool) $row['termsRequired'], (string) $row['country'], (int) $row['withdrawalDays']), true);

                if ($required) {
                    // Which of the two: nothing picked is "missing", anything
                    // else means the pick exists and does not work.
                    if ($picked) {
                        $unreachable[] = $entry;
                    } else {
                        $missing[] = $entry;
                    }
                } elseif (LegalTextKind::Terms === $kind ? $picked : (int) $row['withdrawalDays'] > 0) {
                    $advised[] = $entry;
                }
            }
        }

        return [
            ...$this->perShop(ConfigurationFinding::LegalTextMissing, $missing, true),
            ...$this->perShop(ConfigurationFinding::LegalTextUnreachable, $unreachable, true),
            ...$this->perShop(ConfigurationFinding::LegalTextAdvised, $advised, true),
        ];
    }

    /**
     * The function "Vertrag widerrufen" (D-2, spec D 10, decision E1): a shop
     * with a withdrawal period needs a published page, set on the start point,
     * carrying the element — the way in for a buyer without the mail.
     *
     * Blocking where the shop sits in the EU/EEA (§ 356a BGB, § 13a FAGG,
     * Art. 11a Directive 2011/83/EU), a notice elsewhere — the same line as the
     * withdrawal instruction (ShopLegalRequirements::withdrawalRequired()).
     *
     * One query, placed like the order element's check next door: in an
     * article of the page, or one level down in a container element. Article
     * and elements have to be shown and inside their start/stop windows, and
     * where guests may order the page must not be protected — a withdrawal
     * behind a login is not "leicht zugänglich" (Fable review D-2: the first
     * version checked neither, unlike the legal texts above).
     *
     * @return list<ConfigurationIssue>
     */
    private function withdrawalFunctionIssues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                "SELECT root.id, root.title, root.consho_shop AS shop, s.country, s.withdrawalDays
                   FROM tl_page root
                   JOIN tl_consho_shop s ON s.id = root.consho_shop
              LEFT JOIN tl_page target
                     ON target.id = root.consho_withdrawalFormPage
                    AND target.published = 1
                    AND (target.start = '' OR target.start <= :now)
                    AND (target.stop = '' OR target.stop > :now)
                  WHERE %1\$s
                    AND s.withdrawalDays > 0
                    AND (
                        target.id IS NULL
                        OR (target.protected = 1 AND s.guestCheckout = 1)
                        OR (
                            NOT EXISTS (
                                SELECT 1 FROM tl_content c
                                JOIN tl_article a ON a.id = c.pid AND c.ptable = 'tl_article'
                                WHERE a.pid = target.id AND %2\$s AND c.type = 'consho_withdrawal' AND %3\$s
                            )
                            AND NOT EXISTS (
                                SELECT 1 FROM tl_content nested
                                JOIN tl_content outer_element ON outer_element.id = nested.pid AND nested.ptable = 'tl_content'
                                JOIN tl_article a ON a.id = outer_element.pid AND outer_element.ptable = 'tl_article'
                                WHERE a.pid = target.id
                                  AND %2\$s
                                  AND nested.type = 'consho_withdrawal'
                                  AND %4\$s
                                  AND %5\$s
                            )
                        )
                    )
                  ORDER BY root.id",
                $this->liveRoot('root'),
                self::visible('a', 'published = 1'),
                self::visible('c', 'invisible = 0'),
                self::visible('nested', 'invisible = 0'),
                self::visible('outer_element', 'invisible = 0'),
            ),
            ['now' => $this->minute()],
        );

        $required = [];
        $advised = [];

        foreach ($rows as $row) {
            if (ShopLegalRequirements::withdrawalRequired((string) $row['country'], (int) $row['withdrawalDays'])) {
                $required[] = $row;
            } else {
                $advised[] = $row;
            }
        }

        return [
            ...$this->perShop(ConfigurationFinding::WithdrawalFunctionMissing, $required),
            ...$this->perShop(ConfigurationFinding::WithdrawalFunctionAdvised, $advised),
        ];
    }

    /**
     * The invoice sender (9). Field by field, which is what the separate
     * columns were for (Michael, 2026-09-22).
     *
     * Named in the sentence, not merely counted: "unvollständig" without
     * saying which part sends the operator through six fields comparing them
     * against nothing.
     *
     * @return list<ConfigurationIssue>
     */
    private function senderIssues(): array
    {
        $issues = [];
        $columns = implode(', ', self::SENDER_FIELDS);

        foreach ($this->connection->fetchAllAssociative("SELECT id, title, {$columns} FROM tl_consho_shop ORDER BY id") as $row) {
            $empty = [];

            foreach (self::SENDER_FIELDS as $field) {
                if ('' === trim((string) ($row[$field] ?? ''))) {
                    $empty[] = $field;
                }
            }

            if ([] === $empty) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::InvoiceSenderIncomplete,
                [
                    'shop' => (string) $row['title'],
                    'count' => (string) \count($empty),
                    'fields' => implode(', ', array_map(self::labelOf(...), $empty)),
                ],
                (int) $row['id'],
            );
        }

        return $issues;
    }

    /**
     * A number range for the invoice and for the cancellation, where a chosen
     * payment method issues its document on order (11.3).
     *
     * ⚠️ The CANCELLATION range too, although nothing cancels anything in C.
     * C-10 lets a buyer change the payment method after an invoice was issued,
     * and 15.2 wants both sequences gapless — the moment an invoice can exist,
     * the way to cancel it has to exist as well. Finding that out at the
     * cancellation would mean an invoice that cannot be withdrawn.
     *
     * The trigger comes from the handler, not from a column: it is the
     * handler's decision (DocumentTrigger), and a copy of that decision in a
     * query would be free to drift from it.
     *
     * @return list<ConfigurationIssue>
     */
    private function numberRangeIssues(): array
    {
        $issues = [];

        $methods = $this->connection->fetchAllAssociativeIndexed(
            "SELECT id, type, jsonData, instructions FROM tl_consho_payment_method WHERE published = 1 AND type != ''",
        );

        $ranges = [];

        foreach ($this->connection->fetchAllAssociative('SELECT pid, type FROM tl_consho_number_range') as $row) {
            $ranges[(int) $row['pid']][] = (string) $row['type'];
        }

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, paymentMethods FROM tl_consho_shop ORDER BY id') as $shop) {
            $shopId = (int) $shop['id'];

            $have = $ranges[$shopId] ?? [];

            /*
             * `order` ALWAYS, the document ranges wherever a chosen payment
             * method issues an invoice at all (F4, 2026-09-23; widened in E-2).
             *
             * F4 asked only for methods that issue ON ORDER, because in C
             * nothing else issued. Since D-1 every trigger does — on payment,
             * on shipment — and with "Auf Rechnung" (E-2) the core has a
             * method that issues when the operator reports the parcel: without
             * the range, that report would fail at the number, long after the
             * checkout promised to work.
             *
             * Every shop that takes an order needs a number for it —
             * OrderFactory asks for one at every purchase, and C-7 is the
             * first caller that ever did. Without the range the buy click
             * throws NumberRangeNotConfigured and the buyer gets a 500 after
             * typing an address and choosing methods. The readiness check
             * exists to catch that BEFORE the checkout is rendered.
             *
             * Kept in this source rather than the technical one, although a
             * missing range is a setup matter and not a legal one: all three
             * ranges live in one mask and are created by one person in one
             * go, and splitting the question would mean querying the same
             * table twice to tell them the same thing.
             */
            $need = array_values(array_diff(
                $this->issuesInvoices(MethodSettings::selectedIds($shop['paymentMethods']), $methods)
                    ? ['order', 'invoice', 'cancellation']
                    : ['order'],
                $have,
            ));

            if ([] === $need) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::NumberRangeMissing,
                [
                    'shop' => (string) $shop['title'],
                    'count' => (string) \count($need),
                    'types' => implode(', ', array_map(
                        // From default.php: the start page never loads
                        // tl_consho_number_range, so this printed the keys
                        // (found in the review of D-3, S1).
                        static fn (string $type): string => (string) ($GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'][$type] ?? $type),
                        $need,
                    )),
                    // The keys as well, for the setup page's "create the
                    // missing ranges" (block I) — one answer to "which", not
                    // a second computation next to this one.
                    'typeKeys' => implode(',', $need),
                ],
                $shopId,
            );
        }

        return $issues;
    }

    /**
     * One finding per shop (11.2) — a finding covering three shops would block
     * all three.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ConfigurationIssue>
     */
    private function perShop(ConfigurationFinding $finding, array $rows, bool $withKinds = false): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($grouped as $shopId => $entries) {
            /*
             * ⚠️ START POINTS are counted, not rows.
             *
             * Found in the review of 2026-09-22: for the legal texts a row is
             * a (start point × kind) pair, so one start point missing both
             * texts read "Startpunkte ohne Rechtstext: 2 (Shop DE)" — two
             * announced, one listed. Counted by id, not by title: two start
             * points may legitimately share a name.
             */
            $titles = [];

            foreach ($entries as $entry) {
                $titles[(int) $entry['id']] = (string) $entry['title'];
            }

            $context = [
                'count' => (string) \count($titles),
                'pages' => implode(', ', array_values($titles)),
            ];

            if ($withKinds) {
                /*
                 * The label the checkout shows ("AGB", "Widerrufsbelehrung"),
                 * not the internal key: the operator read "betroffen: terms"
                 * on the dashboard (C-12 acceptance, 2026-09-26). The key
                 * where no language file is loaded — tests, the console.
                 */
                $context['kinds'] = implode(', ', array_unique(array_map(
                    static fn (array $e): string => (string) ($GLOBALS['TL_LANG']['CONSHO']['checkout']['legal'][(string) $e['kind']] ?? $e['kind']),
                    $entries,
                )));
            }

            $issues[] = new ConfigurationIssue($finding, $context, $shopId);
        }

        return $issues;
    }

    /**
     * The mask's label for a field, so the sentence says "Firmenname" and not
     * "invoiceCompany".
     *
     * 🔴 Found in the review of 2026-09-22. The docblock above promised the
     * opposite of what the code did — "named in the sentence, not merely
     * counted" — and then printed column names, which is a different kind of
     * uselessness than a bare count, not a smaller one.
     *
     * The column name stays as the fallback: a finding that says something
     * slightly technical beats one that says nothing because a language file
     * was not loaded.
     */
    private static function labelOf(string $field): string
    {
        $label = $GLOBALS['TL_LANG']['tl_consho_shop'][$field][0] ?? null;

        return \is_string($label) && '' !== $label ? $label : $field;
    }

    /**
     * A root page that actually serves somebody: published, in its window, and
     * with a shop on it. Written once — four queries use it, and four copies
     * would be four chances to forget `published` again (C-5's acceptance).
     *
     * For the setup page every start point with a shop (N1, issuesForSetup()).
     */
    private function liveRoot(string $alias): string
    {
        if ($this->forSetup) {
            return \sprintf("%1\$s.type IN ('root', 'rootfallback') AND %1\$s.consho_shop > 0", $alias);
        }

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
     * Shown by its own flag and inside its own start/stop window — for an
     * article or a content element (D-2, the withdrawal function; the legal
     * texts spell the same out inline).
     */
    private static function visible(string $alias, string $flag): string
    {
        return \sprintf(
            "%1\$s.%2\$s AND (%1\$s.start = '' OR %1\$s.start <= :now) AND (%1\$s.stop = '' OR %1\$s.stop > :now)",
            $alias,
            $flag,
        );
    }

    /**
     * Whether any chosen, installed method issues an invoice — and every
     * trigger does, on order, on payment or on shipment (E-2). A method whose
     * module is gone issues nothing and is not offered either.
     *
     * @param list<int>                               $selected
     * @param array<int|string, array<string, mixed>> $methods  published methods by id
     */
    private function issuesInvoices(array $selected, array $methods): bool
    {
        foreach ($selected as $id) {
            $row = $methods[$id] ?? null;

            if (null !== $row && $this->handlers->has((string) $row['type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The full minute, the way Contao compares start/stop.
     */
    private function minute(): string
    {
        return (string) (int) (floor(time() / 60) * 60);
    }
}

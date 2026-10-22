<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Two findings about withdrawals for the back end start page (D-3). Neither
 * blocks the checkout — this is not a readiness source.
 *
 *  - **Open withdrawals** (F11, Michael 2026-09-27): received and not
 *    decided, or accepted and not refunded — per shop, with the earliest day a
 *    refund falls due (receipt + 14 days, § 357 Abs. 1 BGB, § 14 Abs. 1
 *    FAGG). Withdrawals are rare; the operator must not have to look for them.
 *  - **Number ranges for refunds** (F9): a shop with a withdrawal period that
 *    issues invoices, but has no range for the invoice correction or the
 *    cancellation. It would only notice at "Erstattung buchen".
 */
final class WithdrawalIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        return [...$this->open(), ...$this->unassigned(), ...$this->ranges()];
    }

    /**
     * WA (A5): declarations from the form that matched no order. Not in
     * "Open withdrawals" — that one goes through the order, and these have
     * none yet — but in a finding of their own, per shop they came in
     * through, with the day the first refund would fall due.
     *
     * @return list<ConfigurationIssue>
     */
    private function unassigned(): array
    {
        return $this->grouped(ConfigurationFinding::WithdrawalsUnassigned, $this->connection->fetchAllAssociative(
            "SELECT w.shop, s.id AS shopExists, s.title, w.number, w.receivedAt
               FROM tl_consho_withdrawal w
               LEFT JOIN tl_consho_shop s ON s.id = w.shop
              WHERE w.pid = 0 AND w.state = 'received'
              ORDER BY w.shop, w.receivedAt, w.id",
        ));
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function open(): array
    {
        return $this->grouped(ConfigurationFinding::WithdrawalsOpen, $this->connection->fetchAllAssociative(
            "SELECT o.shop, s.id AS shopExists, s.title, w.number, w.receivedAt
               FROM tl_consho_withdrawal w
               JOIN tl_consho_order o ON o.id = w.pid
               LEFT JOIN tl_consho_shop s ON s.id = o.shop
              WHERE w.state = 'received' OR (w.state = 'accepted' AND w.refundedAt IS NULL)
              ORDER BY o.shop, w.receivedAt, w.id",
        ));
    }

    /**
     * One finding per shop, with the day the first refund falls due.
     *
     * A shop that was deleted keeps its withdrawals — the refund is still
     * owed. A join on the shop dropped them silently (review WA, K5); they
     * come as a general finding now, named by the shop's former id.
     *
     * @param list<array<string, mixed>> $rows ordered by shop, then receipt
     *
     * @return list<ConfigurationIssue>
     */
    private function grouped(ConfigurationFinding $finding, array $rows): array
    {
        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $exists = null !== $entries[0]['shopExists'];

            $issues[] = new ConfigurationIssue(
                $finding,
                [
                    'shop' => $exists ? (string) $entries[0]['title'] : '#'.$shopId,
                    'count' => (string) \count($entries),
                    'numbers' => implode(', ', array_map(static fn (array $e): string => (string) $e['number'], $entries)),
                    'due' => BackendDate::format(WithdrawalSignals::refundDue((int) $entries[0]['receivedAt'])),
                ],
                $exists ? $shopId : 0,
            );
        }

        return $issues;
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function ranges(): array
    {
        $ranges = [];

        foreach ($this->connection->fetchAllAssociative('SELECT pid, type FROM tl_consho_number_range') as $row) {
            $ranges[(int) $row['pid']][] = (string) $row['type'];
        }

        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, withdrawalDays FROM tl_consho_shop ORDER BY id') as $shop) {
            $have = $ranges[(int) $shop['id']] ?? [];

            // No invoices, nothing to correct; no withdrawal, nothing to refund.
            if ((int) $shop['withdrawalDays'] < 1 || !\in_array('invoice', $have, true)) {
                continue;
            }

            $missing = array_values(array_diff(['creditNote', 'cancellation'], $have));

            if ([] === $missing) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::RefundRangeMissing,
                [
                    'shop' => (string) $shop['title'],
                    'types' => implode(', ', array_map(
                        // default.php, which the start page has loaded (review D-3, S1).
                        static fn (string $type): string => (string) ($GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'][$type] ?? $type),
                        $missing,
                    )),
                    // For the setup page (block I), as at numberRangeMissing.
                    'typeKeys' => implode(',', $missing),
                ],
                (int) $shop['id'],
            );
        }

        return $issues;
    }
}

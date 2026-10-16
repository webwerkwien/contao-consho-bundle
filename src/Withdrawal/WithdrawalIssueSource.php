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
        return [...$this->open(), ...$this->ranges()];
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function open(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT o.shop, s.title, w.number, w.receivedAt
               FROM tl_consho_withdrawal w
               JOIN tl_consho_order o ON o.id = w.pid
               JOIN tl_consho_shop s ON s.id = o.shop
              WHERE w.state = 'received' OR (w.state = 'accepted' AND w.refundedAt IS NULL)
              ORDER BY o.shop, w.receivedAt, w.id",
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']][] = $row;
        }

        $issues = [];

        foreach ($byShop as $shopId => $entries) {
            $due = WithdrawalSignals::refundDue((int) $entries[0]['receivedAt']);

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::WithdrawalsOpen,
                [
                    'shop' => (string) $entries[0]['title'],
                    'count' => (string) \count($entries),
                    'numbers' => implode(', ', array_map(static fn (array $e): string => (string) $e['number'], $entries)),
                    'due' => BackendDate::format($due),
                ],
                $shopId,
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * The findings of the confirmation mails (C-9).
 *
 * ── Not sent (7.6) ───────────────────────────────────────────────────────
 *
 * A state, not a log: every order whose confirmation has not gone out, for
 * as long as it has not. It disappears with the first successful send, from
 * the purchase or from "Erneut senden". Orders from before C-9 carry 0 and
 * are not counted (ConfirmationBeforeMailMigration).
 *
 * ── The operator writing to themselves ──────────────────────────────────
 *
 * Microsoft 365 files a mail from an address to the same address under
 * "Sent" only: the operator
 * copy seems never to arrive, and nothing fails that would say why. A hint,
 * not a warning — on other hosts it works, and the shop sells either way.
 *
 * Two queries, both short: the back end asks for findings several times per
 * request (B, section 10). Not a checkout readiness source — neither blocks.
 */
final class DbalConfirmationIssueSource implements ConfigurationIssueSource
{
    /** The count says how many; the numbers say where to start. */
    private const EXAMPLES = 5;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $issues = [];

        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.shop, s.title, o.orderNumber
               FROM tl_consho_order o
          LEFT JOIN tl_consho_shop s ON s.id = o.shop
              WHERE o.confirmationSentAt IS NULL
              ORDER BY o.shop, o.id',
        );

        $byShop = [];

        foreach ($rows as $row) {
            $byShop[(int) $row['shop']]['title'] = (string) ($row['title'] ?? '');
            $byShop[(int) $row['shop']]['numbers'][] = (string) $row['orderNumber'];
        }

        foreach ($byShop as $shopId => $shop) {
            $count = \count($shop['numbers']);

            $issues[] = new ConfigurationIssue(ConfigurationFinding::ConfirmationNotSent, [
                'shop' => $shop['title'],
                'count' => (string) $count,
                'numbers' => implode(', ', \array_slice($shop['numbers'], 0, self::EXAMPLES)).($count > self::EXAMPLES ? ', …' : ''),
            ], $shopId);
        }

        $same = $this->connection->fetchAllAssociative(
            "SELECT s.id, s.title, s.operatorAddress
               FROM tl_consho_shop s
               JOIN tl_consho_mail_transport t ON t.id = s.mailTransport
              WHERE s.operatorAddress != ''
                AND LOWER(TRIM(s.operatorAddress)) = LOWER(TRIM(t.senderAddress))
              ORDER BY s.id",
        );

        foreach ($same as $shop) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::OperatorAddressIsSender,
                ['shop' => (string) $shop['title'], 'address' => trim((string) $shop['operatorAddress'])],
                (int) $shop['id'],
            );
        }

        return $issues;
    }
}

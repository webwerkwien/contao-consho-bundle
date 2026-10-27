<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Doctrine\DBAL\Connection;

/**
 * The "Lost password" page a start point names (K1-2), if a customer can use
 * it: below that very start point, every page on the way published and in
 * its start/stop window, none of them protected.
 *
 * Below the start point because the activation takes the customer group from
 * the start point of the page the password is set on (AccountFromOrder-
 * Activation): a page of another shop would put the account into that shop's
 * group. Published and unprotected because the link in the mail must open
 * the form for someone who is not signed in.
 *
 * Whether Contao's module sits on the page is not checked: modules sit in
 * layouts, elements and insert tags, and a guess would be wrong more often
 * than it helped (as with the registration, CustomerAccessIssueSource).
 */
final class AccountPasswordPage
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function of(int $rootId): int|null
    {
        if ($rootId < 1) {
            return null;
        }

        $pageId = (int) $this->connection->fetchOne("SELECT consho_passwordPage FROM tl_page WHERE id = ? AND type IN ('root', 'rootfallback')", [$rootId]);

        if ($pageId < 1) {
            return null;
        }

        // The full minute, the way Contao compares start/stop.
        $now = intdiv(time(), 60) * 60;

        for ($id = $pageId, $steps = 0; $steps < 100; ++$steps) {
            $page = $this->connection->fetchAssociative('SELECT id, pid, published, protected, start, stop FROM tl_page WHERE id = ?', [$id]);

            if (false === $page || empty($page['published']) || !empty($page['protected'])
                || ('' !== (string) $page['start'] && (int) $page['start'] > $now)
                || ('' !== (string) $page['stop'] && (int) $page['stop'] <= $now)) {
                return null;
            }

            if ((int) $page['id'] === $rootId) {
                return $pageId;
            }

            $id = (int) $page['pid'];

            if ($id < 1) {
                return null;
            }
        }

        return null;
    }
}

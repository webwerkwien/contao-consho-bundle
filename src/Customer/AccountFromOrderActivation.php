<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\MemberModel;
use Contao\Module;
use Doctrine\DBAL\Connection;

/**
 * Opens an account created from a guest order (K1, K1-6) once its first
 * password is set.
 *
 * Contao's "Lost password" module calls `setNewPassword` after it has saved
 * the password and confirmed the token. The token went to the address of the
 * account, so the click proves the mailbox: the account is opened, put into
 * the shop's customer group, and its address counts as confirmed — "Meine
 * Bestellungen" shows the guest orders under it at once (MemberOrders).
 *
 * The group is the one of the shop whose start point carries the password
 * page, as with the registration (RegistrationActivation). Every other
 * account passes through untouched: a forgotten password of an open account
 * is none of Consho's business.
 */
final class AccountFromOrderActivation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RegistrationActivation $registration,
        private readonly MemberOrders $orders,
    ) {
    }

    #[AsHook('setNewPassword')]
    public function __invoke(MemberModel $member, string $password, Module $module): void
    {
        $this->activate((int) $member->id, $this->registration->currentRootId(), time());
    }

    public function activate(int $memberId, int $rootId, int $now): void
    {
        $row = $this->connection->fetchAssociative(
            \sprintf('SELECT email, consho_accountPending, %s AS grp FROM tl_member WHERE id = ?', $this->connection->quoteIdentifier('groups')),
            [$memberId],
        );

        if (false === $row || (int) $row['consho_accountPending'] < 1) {
            return;
        }

        $this->registration->activate($memberId, ['groups' => $row['grp']], $rootId);
        $this->connection->update('tl_member', ['consho_accountPending' => 0, 'tstamp' => $now], ['id' => $memberId]);
        $this->orders->markConfirmed($memberId, (string) $row['email'], $now);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\Module;
use Doctrine\DBAL\Connection;

/**
 * The three places a member's VAT ID is set (MemberVatIdUpdate).
 *
 * Registration runs AFTER RegistrationActivation (priority below its 0): that
 * one writes the groups from the form data and would overwrite groups added
 * before it.
 */
final class MemberVatIdListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MemberVatIdUpdate $update,
        private readonly RegistrationActivation $registration,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[AsHook('createNewUser', priority: -16)]
    public function onRegistration(int|string $id, array $data, Module $module): void
    {
        if ('' === trim((string) ($data['consho_vatId'] ?? ''))) {
            return;
        }

        $this->update->update((int) $id, $this->update->requesterForRoot($this->registration->currentRootId()), time());
    }

    /**
     * On every save of "Persönliche Daten": a changed VAT ID is checked, an
     * unchanged one only once its result is a year old, a removed one clears
     * the result.
     *
     * @param array<string, mixed> $data
     */
    #[AsHook('updatePersonalData')]
    public function onPersonalData(FrontendUser $user, array $data, Module $module): void
    {
        $this->update->update((int) $user->id, $this->update->requesterForRoot($this->registration->currentRootId()), time());
    }

    /**
     * Whoever saves the member in the back end has its groups in front of
     * them: the groups a VAT ID added become the operator's own, the marker
     * goes — before the check, which then only lists what it adds anew.
     *
     * "Persönliche Daten" calls the same callbacks with the FrontendUser
     * (ModulePersonalData). That save is the member's own and is handled by
     * the hook above; the marker must not go there, or the member would
     * confirm their own groups.
     */
    #[AsCallback(table: 'tl_member', target: 'config.onsubmit')]
    public function onBackendSubmit(DataContainer|FrontendUser $dc): void
    {
        if (!$dc instanceof DataContainer) {
            return;
        }

        $memberId = (int) $dc->id;

        if ($memberId < 1) {
            return;
        }

        $this->connection->update('tl_member', ['consho_netGroupsAuto' => null, 'consho_netGroupsAutoAt' => null], ['id' => $memberId]);
        $this->update->update($memberId, $this->update->requesterForMember($memberId), time());
    }
}

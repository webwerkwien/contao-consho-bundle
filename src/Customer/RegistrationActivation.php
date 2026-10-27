<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\Module;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Opens a new account at once and puts it into the shop's customer group
 * (Nachlese, KA1 and KA3) — when the registration module says so
 * (`consho_activateAtOnce`).
 *
 * In Contao's `createNewUser` hook, not in a registration of our own: Contao
 * stays the way in, with its form, its captcha, its admin notification. The
 * hook runs after the member was saved disabled and BEFORE Contao creates the
 * initial version (ModuleRegistration.php, createNewUser), so the version shows
 * the account as it really starts. Nothing saves the member after the hook —
 * which is why a plain UPDATE here is not undone.
 *
 * The group is ADDED to `reg_groups`, never instead of them: the operator may
 * hand out a newsletter group or a group of their own on the same form. Which
 * customer group is the one of the shop whose start point carries the page —
 * a multi-shop installation registers into the shop the visitor is in. No shop
 * there, no group; the account is opened all the same, since the switch said
 * so.
 *
 * No login (KA4): signing the customer in belongs to "Konto in der Kasse
 * anlegen", a later and larger step.
 */
final class RegistrationActivation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    #[AsHook('createNewUser')]
    public function __invoke(int|string $id, array $data, Module $module): void
    {
        // From the row: the field is Consho's own and not declared on Contao's Module.
        if (empty($module->getModel()->row()['consho_activateAtOnce'])) {
            return;
        }

        $this->activate((int) $id, $data, $this->currentRootId());
    }

    /**
     * @param array<string, mixed> $data the member as Contao stored it, `groups` serialised or as an array
     */
    public function activate(int $memberId, array $data, int $rootId): void
    {
        $groups = array_map('intval', StringUtil::deserialize($data['groups'] ?? null, true));
        $customerGroup = $this->customerGroupOf($rootId);

        if ($customerGroup > 0 && !\in_array($customerGroup, $groups, true)) {
            $groups[] = $customerGroup;
        }

        $groups = array_values(array_filter($groups, static fn (int $group): bool => $group > 0));

        $this->connection->update(
            'tl_member',
            [
                'disable' => 0,
                // Contao stores the ids as strings ("checkboxWizard"); keep its shape.
                $this->connection->quoteIdentifier('groups') => [] === $groups ? null : serialize(array_map('strval', $groups)),
            ],
            ['id' => $memberId],
        );
    }

    private function customerGroupOf(int $rootId): int
    {
        if ($rootId < 1) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT s.customerGroup FROM tl_page r JOIN tl_consho_shop s ON s.id = r.consho_shop WHERE r.id = ?',
            [$rootId],
        );
    }

    /**
     * The front end has walked the tree already (`rootId` is filled by the
     * time a module renders), so no loadDetails() here — see ShopResolver.
     */
    public function currentRootId(): int
    {
        $page = $this->requests->getCurrentRequest()?->attributes->get('pageModel');

        if (!$page instanceof PageModel) {
            return 0;
        }

        return 'root' === $page->type ? (int) $page->id : (int) $page->rootId;
    }
}

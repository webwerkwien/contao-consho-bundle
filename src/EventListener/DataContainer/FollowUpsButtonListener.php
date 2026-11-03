<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;

/**
 * "Nachträge" (block Zahlung Mollie, 9) in every row, at the same place:
 *
 *  - greyed, not a link, where the provider reported nothing — hidden, it
 *    would shift the icons after it from row to row
 *  - red while something waits for the operator: a refund not yet recorded
 *    or assigned, a chargeback that stands
 *  - plain once all is settled — the page still shows what happened
 */
class FollowUpsButtonListener
{
    public const ICON_OPEN = 'bundles/contaoconsho/icons/follow-ups--open.svg';

    /** @var array<int, bool>|null per order with events: whether one is open; read once per list */
    private array|null $orders = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'list.operations.followUps.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $this->orders ??= $this->read();
        $orderId = (int) ($operation->getRecord()['id'] ?? 0);

        if (!isset($this->orders[$orderId])) {
            $operation->disable();
            // Without a link the title only lands in the icon's alt; Contao's
            // tooltip shows it once the icon itself is a target (as InUseDeleteTitleListener does).
            $operation['title'] = (string) ($GLOBALS['TL_LANG']['tl_consho_order']['followUpsNone'] ?? $operation['title']);
            $operation['iconAttributes']->set('data-contao--tooltips-target', 'tooltip');

            return;
        }

        if ($this->orders[$orderId]) {
            $operation['icon'] = self::ICON_OPEN;
        }
    }

    /**
     * @return array<int, bool>
     */
    private function read(): array
    {
        $orders = [];

        // A reversed chargeback stays `open` without anything left to do.
        foreach ($this->connection->fetchAllAssociative(
            "SELECT pid, MAX(CASE WHEN state = 'open' AND (kind = 'refund' OR reversedAt = 0) THEN 1 ELSE 0 END) AS waiting
               FROM tl_consho_provider_event GROUP BY pid",
        ) as $row) {
            $orders[(int) $row['pid']] = 1 === (int) $row['waiting'];
        }

        return $orders;
    }
}

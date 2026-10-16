<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;

/**
 * The withdrawal and complaint buttons in the order list (D-3, F13).
 *
 * Michael: „das Icon für Widerrufe ist immer grün und anwählbar, es wäre
 * besser wenn es nur bei einem widerruf so ist" — „was für das Widerruf icon
 * gilt, gilt auch für die Reklamation".
 *
 *  - Withdrawals come only from the customer. Without one there is nothing
 *    to open: the button is disabled like Contao's own.
 *  - Complaints are recorded by the operator. A disabled button would lock
 *    out the first one, so without a complaint it turns grey but stays a
 *    link, and says so in its title.
 *
 * One query per table and request, not one per row: the orders that have
 * any are read once.
 */
class OrderAftercareButtonListener
{
    public const COMPLAINT_ICON_NONE = 'bundles/contaoconsho/icons/important--none.svg';

    /** @var array<string, array<int, true>> */
    private array $orders = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'list.operations.withdrawals.button')]
    public function withdrawals(DataContainerOperation $operation): void
    {
        if (!$this->has('tl_consho_withdrawal', $operation)) {
            $operation->disable();
        }
    }

    #[AsCallback(table: 'tl_consho_order', target: 'list.operations.complaints.button')]
    public function complaints(DataContainerOperation $operation): void
    {
        if ($this->has('tl_consho_complaint', $operation)) {
            return;
        }

        $operation['icon'] = self::COMPLAINT_ICON_NONE;
        $operation['title'] = (string) ($GLOBALS['TL_LANG']['tl_consho_order']['complaintsNone'] ?? $operation['title']);
    }

    private function has(string $table, DataContainerOperation $operation): bool
    {
        $this->orders[$table] ??= array_fill_keys(
            array_map('intval', $this->connection->fetchFirstColumn("SELECT DISTINCT pid FROM $table")),
            true,
        );

        return isset($this->orders[$table][(int) ($operation->getRecord()['id'] ?? 0)]);
    }
}

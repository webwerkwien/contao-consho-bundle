<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * The number of a complaint (block Reklamation): the order number with "-R"
 * and one more than the highest number among the order's complaints — a
 * deleted one leaves a gap, which is fine: it is no document number. The
 * unique key (pid, number) stays the guard.
 *
 * The state changes only on the page "Reklamation entscheiden".
 */
class ComplaintListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[AsCallback(table: 'tl_consho_complaint', target: 'config.oncreate')]
    public function number(string $table, int $insertId, array $fields, DataContainer $dc): void
    {
        $orderId = (int) ($fields['pid'] ?? 0);
        $orderNumber = $this->connection->fetchOne('SELECT orderNumber FROM tl_consho_order WHERE id = ?', [$orderId]);

        if (!\is_string($orderNumber) || '' === $orderNumber) {
            return;
        }

        $highest = 0;

        foreach ($this->connection->fetchFirstColumn('SELECT number FROM tl_consho_complaint WHERE pid = ? AND id <> ?', [$orderId, $insertId]) as $number) {
            if (1 === preg_match('/-R(\d+)$/', (string) $number, $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        $this->connection->update('tl_consho_complaint', ['number' => \sprintf('%s-R%d', $orderNumber, $highest + 1)], ['id' => $insertId]);
    }
}

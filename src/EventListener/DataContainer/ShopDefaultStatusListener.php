<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * A shop created in the back end starts with five order statuses: new,
 * paid, shipped, completed, cancelled — each with the flag that makes it do
 * what its name says. The titles come in the back end language of whoever
 * creates the shop and are the operator's own from then on; customers never
 * see them. The automatic status changes on the shop stay off: which status
 * follows a payment is the operator's decision.
 *
 * Only on "new" (oncreate): a copied shop takes its statuses along as child
 * rows, and a shop created on the console sets its own. A shop left unsaved
 * is removed by Contao together with its child rows.
 */
final class ShopDefaultStatusListener
{
    /**
     * key => colour and the one flag it sets
     */
    private const STATUSES = [
        'new' => ['open', null],
        'paid' => ['progress', 'isPaid'],
        'shipped' => ['progress', 'isShipped'],
        'completed' => ['done', 'isCompleted'],
        'cancelled' => ['attention', 'isCancelled'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'config.oncreate')]
    public function __invoke(string $table, int|string $insertId, array $fields, DataContainer $dc): void
    {
        $shopId = (int) $insertId;

        if ($shopId < 1 || false !== $this->connection->fetchOne('SELECT id FROM tl_consho_order_status WHERE pid = ? LIMIT 1', [$shopId])) {
            return;
        }

        $sorting = 0;

        foreach (self::STATUSES as $key => [$colour, $flag]) {
            $sorting += 128;
            $row = [
                'pid' => $shopId,
                'sorting' => $sorting,
                'tstamp' => time(),
                'title' => (string) ($GLOBALS['TL_LANG']['CONSHO']['defaultStatus'][$key] ?? $key),
                'colour' => $colour,
            ];

            if (null !== $flag) {
                $row[$flag] = 1;
            }

            $this->connection->insert('tl_consho_order_status', $row);
        }
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Date;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The delivery masks in the back end (D-1).
 *
 *  - A new delivery comes with the goods still open (Z5): the operator
 *    removes what is not in the parcel instead of typing what is.
 *  - An item offers only the GOODS of its own order. Until D-1 the field
 *    listed every line of every order, shipping lines included, through a
 *    foreignKey.
 *  - The list says whether a delivery is reported; the report button goes
 *    once it is.
 */
class DeliveryListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[AsCallback(table: 'tl_consho_delivery', target: 'config.oncreate')]
    public function prefill(string $table, int $insertId, array $fields, DataContainer $dc): void
    {
        $orderId = (int) ($fields['pid'] ?? 0);
        $balance = $orderId > 0 ? $this->balances->of($orderId) : null;

        if (null === $balance) {
            return;
        }

        $now = time();
        $this->connection->update('tl_consho_delivery', ['shippedAt' => $now], ['id' => $insertId]);

        // Digital lines are released, not packed (F-4, F17).
        $digital = array_map(intval(...), $this->connection->fetchFirstColumn("SELECT id FROM tl_consho_order_item WHERE pid = ? AND lineType = 'product' AND kind <> 'physical'", [$orderId]));

        /*
         * Only reported deliveries count in the balance, so a second delivery
         * not yet reported was filled with the same open quantities — and the
         * first report then refused "more than open" (spec D, review 1, K3).
         * What other unreported deliveries of this order already carry is
         * subtracted; nothing left, no line.
         */
        $planned = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT di.orderItem, SUM(di.quantity) FROM tl_consho_delivery_item di
             INNER JOIN tl_consho_delivery d ON d.id = di.pid
             WHERE d.pid = ? AND d.reportedAt IS NULL AND d.id <> ?
             GROUP BY di.orderItem',
            [$orderId, $insertId],
        ));

        foreach ($balance->openGoods as $itemId => $quantity) {
            if (\in_array($itemId, $digital, true)) {
                continue;
            }

            $left = BigDecimal::of($quantity)->minus($planned[$itemId] ?? '0');

            if (!$left->isPositive()) {
                continue;
            }

            $this->connection->insert('tl_consho_delivery_item', [
                'pid' => $insertId,
                'tstamp' => $now,
                'orderItem' => $itemId,
                'quantity' => (string) $left,
            ]);
        }
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'list.operations.report.button')]
    public function reportButton(DataContainerOperation $operation): void
    {
        if (null !== ($operation->getRecord()['reportedAt'] ?? null)) {
            $operation->hide();
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_delivery', target: 'list.label.label')]
    public function deliveryLabel(array $row): string
    {
        $shippedAt = (int) ($row['shippedAt'] ?? 0);
        $parts = [$shippedAt > 0 ? Date::parse(Date::getNumericDateFormat(), $shippedAt) : ''];

        if ('' !== (string) ($row['trackingNumber'] ?? '')) {
            $parts[] = StringUtil::specialchars((string) $row['trackingNumber']);
        }

        $reportedAt = $row['reportedAt'] ?? null;
        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery'];
        $parts[] = null === $reportedAt
            ? '<span class="tl_gray">'.($lang['notReported'] ?? 'not reported').'</span>'
            : \sprintf($lang['reportedOn'] ?? '%s', Date::parse(Date::getNumericDatimFormat(), (int) $reportedAt));

        return implode(' — ', array_filter($parts, static fn (string $p): bool => '' !== $p));
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_delivery_item', target: 'fields.orderItem.options')]
    public function orderItemOptions(DataContainer|null $dc = null): array
    {
        $deliveryId = null === $dc ? 0 : (int) ($dc->getCurrentRecord()['pid'] ?? 0);
        $orderId = $deliveryId > 0 ? (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_delivery WHERE id = ?', [$deliveryId]) : 0;

        if ($orderId < 1) {
            return [];
        }

        $options = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, productName, sku, lineType, kind FROM tl_consho_order_item WHERE pid = ? ORDER BY sorting, id', [$orderId]) as $row) {
            if (OrderLineType::stored((string) $row['lineType'])->isGoods() && ProductKind::stored((string) $row['kind'])->isPhysical()) {
                $options[(int) $row['id']] = (string) $row['productName'].('' !== (string) $row['sku'] ? ' ('.$row['sku'].')' : '');
            }
        }

        return $options;
    }

    /**
     * "2 × Wanderstock" — the raw decimal(10,4) would read "Wanderstock ×
     * 2.0000" (Prüfungsnotiz, Fall 30).
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_delivery_item', target: 'list.label.label')]
    public function itemLabel(array $row): string
    {
        $name = $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE id = ?', [(int) ($row['orderItem'] ?? 0)]);
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');

        return \sprintf('%s × %s', $this->money->quantity((string) ($row['quantity'] ?? '0'), $language), StringUtil::specialchars((string) $name));
    }
}

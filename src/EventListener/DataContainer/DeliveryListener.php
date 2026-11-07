<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Date;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
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
 *  - The list is a column list: kind, shipped, carrier, tracking number,
 *    reported. The report button goes once a delivery is reported. A
 *    replacement delivery links to its complaint (RK-5).
 */
class DeliveryListener
{
    private readonly DeliveryContents $contents;

    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly MoneyFormatter $money,
        DeliveryContents|null $contents = null,
        // "Leistung erbracht" at the row of an electronic delivery.
        private readonly DeliveryServiceListener|null $services = null,
        // RK-5, S8: the link of a replacement delivery to its complaint.
        private readonly UrlGeneratorInterface|null $router = null,
    ) {
        $this->contents = $contents ?? new DeliveryContents($connection, $balances);
    }

    /**
     * What is still available goes in (DeliveryContents) — the mask's
     * "Lieferung vollständig", ticked from the start.
     *
     * @param array<string, mixed> $fields
     */
    #[AsCallback(table: 'tl_consho_delivery', target: 'config.oncreate')]
    public function prefill(string $table, int $insertId, array $fields, DataContainer $dc): void
    {
        $orderId = (int) ($fields['pid'] ?? 0);

        if ($orderId < 1 || null === $this->balances->of($orderId)) {
            return;
        }

        $now = time();
        $this->connection->update('tl_consho_delivery', ['shippedAt' => $now], ['id' => $insertId]);
        $this->contents->replace($insertId, $this->contents->available($orderId, $insertId), $now);
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'list.operations.report.button')]
    public function reportButton(DataContainerOperation $operation): void
    {
        if (null !== ($operation->getRecord()['reportedAt'] ?? null)) {
            $operation->hide();
        }
    }

    /**
     * "Benachrichtigung erneut senden" on every reported parcel, sent or not
     * — a customer can lose a mail, as at the order confirmation. Not on an
     * electronic release: its notice is the release mail. Not on an
     * anonymised order: there is no address left to send to.
     */
    #[AsCallback(table: 'tl_consho_delivery', target: 'list.operations.resendNotification.button')]
    public function resendNotificationButton(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();

        if (
            null === ($record['reportedAt'] ?? null)
            || !DeliveryReported::isParcel($this->connection, (int) ($record['id'] ?? 0))
            || false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_order WHERE id = ? AND anonymizedAt IS NOT NULL', [(int) ($record['pid'] ?? 0)])
        ) {
            $operation->hide();
        }
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'list.operations.deliveryNote.button')]
    public function deliveryNoteButton(DataContainerOperation $operation): void
    {
        $id = (int) ($operation->getRecord()['id'] ?? 0);

        if (false === $this->connection->fetchOne("SELECT 1 FROM tl_consho_document WHERE delivery = ? AND type = 'deliveryNote'", [$id])) {
            $operation->hide();
        }
    }

    /**
     * One cell per field in `list.label.fields`: kind, shipped, carrier,
     * tracking number, reported. The services rendered with the parcel
     * ("Leistung erbracht") follow the reported cell.
     *
     * ⚠️ An ARRAY — with showColumns Contao throws a string away.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_delivery', target: 'list.label.label')]
    public function deliveryLabel(array $row): array
    {
        $shippedAt = (int) ($row['shippedAt'] ?? 0);

        return [
            $this->replacementMarker($row),
            $shippedAt > 0 ? Date::parse(Date::getNumericDateFormat(), $shippedAt) : '',
            StringUtil::specialchars((string) ($row['carrier'] ?? '')),
            StringUtil::specialchars((string) ($row['trackingNumber'] ?? '')),
            $this->notification($row).($this->services?->rowSuffix((int) ($row['id'] ?? 0)) ?? ''),
        ];
    }

    /**
     * The column "Benachrichtigung": when the mail about the parcel left; red
     * where it failed; for a delivery without a recorded mail (0: before the
     * field, or an electronic release) the moment it was reported.
     *
     * @param array<string, mixed> $row
     */
    public function notification(array $row): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery'] ?? [];
        $reportedAt = $row['reportedAt'] ?? null;
        $notifiedAt = $row['notifiedAt'] ?? null;

        if (null === $reportedAt) {
            return '<span class="tl_gray">'.($lang['notReported'] ?? 'not reported').'</span>';
        }

        if (null === $notifiedAt) {
            return '<span class="consho-overdue">'.($lang['notNotified'] ?? 'not sent').'</span>';
        }

        return Date::parse(Date::getNumericDatimFormat(), (int) $notifiedAt > 0 ? (int) $notifiedAt : (int) $reportedAt);
    }

    /**
     * The kind column: the lorry of the "Lieferungen" button for a delivery
     * of the order, the cracked glass of the "Reklamationen" button for a
     * replacement (RK-5, S8). A replacement links to its complaint's page and
     * names the complaint in the title — it is not a delivery of the order.
     *
     * @param array<string, mixed> $row
     */
    public function replacementMarker(array $row): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery'] ?? [];
        $complaintId = (int) ($row['complaint'] ?? 0);

        if ($complaintId < 1) {
            return \sprintf('<span class="consho-signal consho-signal--kind consho-signal--kind-delivery" title="%s"></span>', StringUtil::specialchars((string) ($lang['kindDelivery'] ?? 'Delivery')));
        }

        $number = (string) $this->connection->fetchOne('SELECT number FROM tl_consho_complaint WHERE id = ?', [$complaintId]);
        $label = StringUtil::specialchars(\sprintf((string) ($lang['replacementLabel'] ?? '%s'), $number));
        $icon = \sprintf('<span class="consho-signal consho-signal--kind consho-signal--kind-replacement" title="%s"></span>', $label);

        if (null === $this->router) {
            return $icon;
        }

        $url = $this->router->generate('contao_backend', ['do' => 'consho_order', 'table' => 'tl_consho_complaint', 'key' => 'processComplaint', 'id' => $complaintId, 'pid' => (int) ($row['pid'] ?? 0)]);

        return '<a href="'.StringUtil::specialchars($url).'" title="'.$label.'">'.$icon.'</a>';
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
     * 2.0000" (MySQL returns the trailing zeros, SQLite does not).
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

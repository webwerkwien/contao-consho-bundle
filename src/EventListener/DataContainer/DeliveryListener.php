<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Date;
use Contao\Image;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
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
 *    once it is. A replacement delivery names its complaint (RK-5).
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
        // RK-5, S8: the icon and the link of a replacement delivery.
        private readonly ContaoFramework|null $framework = null,
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

    #[AsCallback(table: 'tl_consho_delivery', target: 'list.operations.deliveryNote.button')]
    public function deliveryNoteButton(DataContainerOperation $operation): void
    {
        $id = (int) ($operation->getRecord()['id'] ?? 0);

        if (false === $this->connection->fetchOne("SELECT 1 FROM tl_consho_document WHERE delivery = ? AND type = 'deliveryNote'", [$id])) {
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
        $parts[] = $this->replacementMarker($row);
        $parts[] = null === $reportedAt
            ? '<span class="tl_gray">'.($lang['notReported'] ?? 'not reported').'</span>'
            : \sprintf($lang['reportedOn'] ?? '%s', Date::parse(Date::getNumericDatimFormat(), (int) $reportedAt));

        return implode(' — ', array_filter($parts, static fn (string $p): bool => '' !== $p))
            .($this->services?->rowSuffix((int) ($row['id'] ?? 0)) ?? '');
    }

    /**
     * RK-5, S8: a replacement delivery says which complaint it answers, with
     * a link to the complaint's page — it is not a delivery of the order.
     *
     * @param array<string, mixed> $row
     */
    public function replacementMarker(array $row): string
    {
        $complaintId = (int) ($row['complaint'] ?? 0);

        if ($complaintId < 1) {
            return '';
        }

        $number = (string) $this->connection->fetchOne('SELECT number FROM tl_consho_complaint WHERE id = ?', [$complaintId]);
        $label = StringUtil::specialchars(\sprintf((string) ($GLOBALS['TL_LANG']['tl_consho_delivery']['replacementLabel'] ?? '%s'), $number));
        $icon = null === $this->framework ? '' : (string) $this->framework->getAdapter(Image::class)->getHtml('sync.svg', $label).' ';

        if (null === $this->router) {
            return $icon.$label;
        }

        $url = $this->router->generate('contao_backend', ['do' => 'consho_order', 'table' => 'tl_consho_complaint', 'key' => 'processComplaint', 'id' => $complaintId, 'pid' => (int) ($row['pid'] ?? 0)]);

        return $icon.'<a href="'.StringUtil::specialchars($url).'">'.$label.'</a>';
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

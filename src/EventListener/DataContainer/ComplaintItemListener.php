<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Event\DataContainerRecordLabelEvent;
use Contao\DataContainer;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The lines of a complaint (block Reklamation).
 *
 * Only goods lines of the complaint's order are offered — charge lines
 * (shipping, payment fee) are no goods a defect could concern. Each line once
 * per complaint: a second defect on the same line is a complaint of its own.
 * The quantity is at most what was ordered; complaints are not counted
 * against each other here, that matters only where money or stock moves.
 */
class ComplaintItemListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_complaint_item', target: 'fields.orderItem.options')]
    public function options(DataContainer $dc): array
    {
        $options = [];

        foreach ($this->lines((int) ($dc->getCurrentRecord()['pid'] ?? 0)) as $id => $line) {
            $options[$id] = \sprintf('%s (%s)', $line['productName'], $this->quantityText($line['quantity']));
        }

        return $options;
    }

    /**
     * Product, quantity, defect. Contao's own cells would show the option
     * text with the ordered quantity ("Hiking pole (2)") and the raw
     * decimal(10,4) ("2.0000").
     *
     * ⚠️ An ARRAY — with showColumns Contao throws a string away.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_complaint_item', target: 'list.label.label')]
    public function label(array $row): array
    {
        return [
            StringUtil::specialchars($this->productName((int) ($row['orderItem'] ?? 0))),
            $this->quantityText((string) ($row['quantity'] ?? '0')),
            StringUtil::specialchars(trim((string) ($row['defect'] ?? ''))),
        ];
    }

    /**
     * The headline names the product ("… › Hiking pole"), not "Position ID 3".
     */
    #[AsEventListener]
    public function recordLabel(DataContainerRecordLabelEvent $event): void
    {
        if (null !== $event->getLabel() || !str_starts_with($event->getIdentifier(), 'contao.db.tl_consho_complaint_item.')) {
            return;
        }

        $name = $this->productName((int) ($event->getData()['orderItem'] ?? 0));

        if ('' !== $name) {
            $event->setLabel($name);
        }
    }

    private function productName(int $orderItem): string
    {
        return (string) $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE id = ?', [$orderItem]);
    }

    #[AsCallback(table: 'tl_consho_complaint_item', target: 'fields.orderItem.save')]
    public function orderItem(mixed $value, DataContainer $dc): int
    {
        $record = $dc->getCurrentRecord() ?? [];
        $complaint = (int) ($record['pid'] ?? 0);
        $line = (int) $value;

        if (!isset($this->lines($complaint)[$line])) {
            throw new \RuntimeException($this->text('notALine', 'Please choose a goods line of this order.'));
        }

        $twice = $this->connection->fetchOne(
            'SELECT id FROM tl_consho_complaint_item WHERE pid = ? AND orderItem = ? AND id <> ?',
            [$complaint, $line, (int) ($record['id'] ?? 0)],
        );

        if (false !== $twice) {
            throw new \RuntimeException($this->text('twice', 'This item is already part of the complaint.'));
        }

        return $line;
    }

    #[AsCallback(table: 'tl_consho_complaint_item', target: 'fields.quantity.save')]
    public function quantity(mixed $value, DataContainer $dc): string
    {
        $record = $dc->getCurrentRecord() ?? [];

        // The line chosen in this very submission: the stored record may
        // still hold the previous one, or none on a new line.
        $posted = $this->requests->getCurrentRequest()?->request->get('orderItem');
        $line = null !== $posted && '' !== $posted ? (int) $posted : (int) ($record['orderItem'] ?? 0);
        $ordered = $this->lines((int) ($record['pid'] ?? 0))[$line]['quantity'] ?? '0';
        $quantity = trim((string) $value);

        if (!is_numeric($quantity)
            || BigDecimal::of($quantity)->isLessThanOrEqualTo(0)
            || BigDecimal::of($quantity)->isGreaterThan($ordered)
        ) {
            throw new \RuntimeException(\sprintf($this->text('tooMuch', 'Please enter a quantity above 0 and at most %s.'), $this->quantityText($ordered)));
        }

        return $quantity;
    }

    /**
     * The goods lines of the complaint's order, by id.
     *
     * @return array<int, array{productName: string, quantity: string}>
     */
    private function lines(int $complaint): array
    {
        $lines = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT i.id, i.productName, i.quantity FROM tl_consho_order_item i
              INNER JOIN tl_consho_complaint c ON c.pid = i.pid
              WHERE c.id = ? AND i.lineType = 'product'
              ORDER BY i.id",
            [$complaint],
        ) as $row) {
            $lines[(int) $row['id']] = ['productName' => (string) $row['productName'], 'quantity' => (string) $row['quantity']];
        }

        return $lines;
    }

    // "2" and "2,5" rather than the column's "2.0000".
    private function quantityText(string $quantity): string
    {
        return is_numeric($quantity) ? $this->money->quantity($quantity, (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de')) : $quantity;
    }

    private function text(string $key, string $fallback): string
    {
        return (string) ($GLOBALS['TL_LANG']['tl_consho_complaint_item'][$key] ?? $fallback);
    }
}

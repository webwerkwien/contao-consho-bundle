<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\Date;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\StatusColour;

/**
 * The order list line: number, date, name, amount, and three signals.
 *
 * Everything an operator needs to decide whether an order needs them today has
 * to be on this line. Opening thirty records to find the one waiting for
 * payment is not an overview.
 */
#[AsCallback(table: 'tl_consho_order', target: 'list.label.label')]
class OrderLabelListener
{
    /**
     * Order id => [payment colour, delivery colour], for this request only.
     *
     * ⚠️ The states are NOT preloaded for the whole page, and that is a
     * decision rather than an oversight. A label callback is handed one row at
     * a time and never learns which other rows the list holds; preloading would
     * mean rebuilding Contao's list query — filters, search, sorting, limit —
     * next to the one Contao already ran, and keeping the copy in step forever.
     *
     * Instead each row asks OrderBalanceReader, which costs a handful of
     * lookups on indexed `pid` columns (D-1: the list and the order page
     * count the same way now, and the list no longer counts shipping lines
     * as goods). Should that ever hurt, the answer is a materialised column
     * and not a second query builder.
     *
     * The third entry is the payment STATE for the title: the colour alone
     * cannot name it — "refunded" is grey like "open", and a cancelled,
     * refunded order read "Zahlung: offen" (found by the dashboard, 2026-10-07).
     *
     * @var array<int, array{0: StatusColour, 1: StatusColour, 2: string}>
     */
    private array $states = [];

    /** @var array<int, array{0: StatusColour, 1: string}> */
    private array $statuses = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    /**
     * One entry per field in `list.label.fields`, in that order.
     *
     * ⚠️ It has to be an ARRAY. With showColumns switched on, Contao throws a
     * string return away and falls back to its own formatted values —
     * DataContainer::generateRecordLabel ends in
     * `return is_array($label) ? $label : $args`. A string here would not
     * error; the carefully built line would simply never appear.
     *
     * The signals lead the row rather than trail it. At the end they would sit
     * against the operation buttons — coloured glyphs beside coloured glyphs,
     * one set meaning "state" and the other "click me". In the first column the
     * eye runs straight down them.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    public function __invoke(array $row, string $label): array
    {
        return [
            $this->signals((int) ($row['id'] ?? 0), $row),
            '<strong>'.StringUtil::specialchars((string) ($row['orderNumber'] ?? '')).'</strong>',
            $this->formatDate($row),
            StringUtil::specialchars(trim(($row['firstname'] ?? '').' '.($row['lastname'] ?? ''))),
            $this->formatAmount($row),
        ];
    }

    private function formatDate(array $row): string
    {
        $createdAt = (int) ($row['createdAt'] ?? 0);

        // 0 is not a date, it is the absence of one. Rendering it would put
        // 01.01.1970 in the list and send someone looking for a broken order.
        return $createdAt > 0 ? Date::parse(Date::getNumericDateFormat(), $createdAt) : '';
    }

    /**
     * The amount is stored as decimal(10,4) so that unit prices survive
     * division. Printed raw it reads "24.0000", which is not a price anyone
     * quotes — hence two decimals and the currency the order was placed in,
     * not today's shop setting.
     */
    private function formatAmount(array $row): string
    {
        $total = (string) ($row['grandTotal'] ?? '0');
        $currency = StringUtil::specialchars((string) ($row['currency'] ?? ''));

        return trim(number_format((float) $total, 2, ',', '.').' '.$currency);
    }

    private function signals(int $orderId, array $row): string
    {
        [$payment, $delivery, $paymentState] = $this->statesFor($orderId);
        [$statusColour, $statusTitle] = $this->statusFor((int) ($row['orderStatus'] ?? 0));

        return '<span class="consho-signals">'
            .$this->signal('store', $statusColour, $statusTitle)
            .$this->signal('sync', $payment, $this->label('payment', $paymentState))
            .$this->signal('copy', $delivery, $this->label('delivery', $delivery->value))
            .'</span>';
    }

    /**
     * The icon carries the dimension, the colour carries the state — and the
     * title carries both in words.
     *
     * That last part is not decoration. Red and green are the two states an
     * operator most needs to tell apart, and red-green is the most common
     * colour blindness there is. A list that encodes its meaning in hue alone
     * is unreadable for roughly one man in twelve.
     */
    private function signal(string $icon, StatusColour $colour, string $title): string
    {
        return \sprintf(
            '<span class="consho-signal consho-signal--%s consho-signal--%s" title="%s"></span>',
            $icon,
            $colour->value,
            StringUtil::specialchars($title),
        );
    }

    /**
     * Cached by STATUS id, not by order id — a shop has a handful of statuses
     * and hundreds of orders, so a page of thirty rows costs about five
     * queries here, not thirty.
     *
     * @return array{0: StatusColour, 1: string}
     */
    private function statusFor(int $statusId): array
    {
        if (isset($this->statuses[$statusId])) {
            return $this->statuses[$statusId];
        }

        $status = $this->connection->fetchAssociative(
            'SELECT colour, title FROM tl_consho_order_status WHERE id = ?',
            [$statusId],
        );

        if (false === $status) {
            return $this->statuses[$statusId] = [
                StatusColour::Open,
                $this->label('status', 'none'),
            ];
        }

        return $this->statuses[$statusId] = [
            StatusColour::tryFrom((string) $status['colour']) ?? StatusColour::Open,
            (string) $status['title'],
        ];
    }

    /**
     * @return array{0: StatusColour, 1: StatusColour, 2: string}
     */
    private function statesFor(int $orderId): array
    {
        if (isset($this->states[$orderId])) {
            return $this->states[$orderId];
        }

        $balance = $this->balances->of($orderId);

        if (null === $balance) {
            return $this->states[$orderId] = [StatusColour::Open, StatusColour::Open, PaymentState::Open->value];
        }

        return $this->states[$orderId] = [
            StatusColour::forPayment($balance->paymentState),
            StatusColour::forDelivery($balance->deliveryState),
            $balance->paymentState->value,
        ];
    }

    /**
     * Falls back to the key itself rather than to an empty string: a missing
     * translation should look wrong, not look like an unlabelled icon.
     */
    private function label(string $dimension, string $key): string
    {
        return $GLOBALS['TL_LANG']['tl_consho_order']['signals'][$dimension][$key]
            ?? $dimension.'.'.$key;
    }
}

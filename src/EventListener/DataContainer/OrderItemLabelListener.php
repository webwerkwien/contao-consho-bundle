<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\Date;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The columns of the order's line list (DL4, spec 6.4): name, kind, quantity,
 * unit price, tax rate, line total, and a state.
 *
 * Amounts as money in the order's currency and quantities without the four
 * stored decimals — the list read "× 1.0000 = 12.5000".
 *
 * The state of a service line: when it was reported as rendered, that
 * "Leistung erbracht" is in the delivery list once released
 * (DeliveryServiceListener), or when the release comes.
 *
 * ⚠️ An ARRAY, one entry per field in `list.label.fields`: with showColumns
 * Contao discards a string (see tl_consho_order).
 */
class OrderItemLabelListener
{
    /** The charge line types with an icon in backend.css. */
    private const CHARGE_ICONS = ['shipping', 'paymentFee', 'paymentDiscount', 'discount'];

    /** @var array<int, string> order id => currency, for this request */
    private array $currencies = [];

    public function __construct(
        private readonly Connection|null $connection = null,
        private readonly DigitalRelease|null $release = null,
        private readonly MoneyFormatter|null $money = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_order_item', target: 'list.label.label')]
    public function __invoke(array $row, string $label): array
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_order_item'] ?? [];
        $lineType = (string) ($row['lineType'] ?? '');
        $currency = $this->currency((int) ($row['pid'] ?? 0));

        if ('product' === $lineType) {
            $kind = ProductKind::stored((string) ($row['kind'] ?? ''));
            $kindLabel = (string) ($lang['kinds'][$kind->value] ?? $kind->value);
        } else {
            $kind = null;
            $kindLabel = (string) ($lang['lineTypes'][$lineType] ?? $lineType);
        }

        // A product line shows the icon of the order list's kind column, a
        // charge line one of its type (backend.css) — both named in the
        // title. A type without an icon stays in words.
        $iconKey = null !== $kind ? $kind->value : (\in_array($lineType, self::CHARGE_ICONS, true) ? $lineType : null);
        $kindColumn = null === $iconKey
            ? StringUtil::specialchars($kindLabel)
            : \sprintf(
                '<span class="consho-signal consho-signal--kind consho-signal--kind-%s" title="%s"></span>',
                $iconKey,
                StringUtil::specialchars($kindLabel),
            );

        return [
            $kindColumn,
            StringUtil::specialchars((string) ($row['productName'] ?? '')),
            $this->quantity((string) ($row['quantity'] ?? '0')),
            $this->amount($this->unitGross($row, $currency), $currency),
            $this->quantity((string) ($row['taxRate'] ?? '0')).' %',
            $this->amount((string) ($row['lineTotal'] ?? '0'), $currency),
            null === $kind ? '' : StringUtil::specialchars($this->state($row, $kind, $lang)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $lang
     */
    private function state(array $row, ProductKind $kind, array $lang): string
    {
        if (null !== ($row['performedAt'] ?? null) && '' !== (string) $row['performedAt']) {
            return \sprintf((string) ($lang['performedOn'] ?? 'performed on %s'), Date::parse(Date::getNumericDateFormat(), (int) $row['performedAt']));
        }

        if (ProductKind::Service === $kind && 0 === (int) ($row['subscriptionRule'] ?? 0)) {
            return $this->notReleasedNote($row, $lang) ?? '';
        }

        return '';
    }

    /**
     * The line keeps the NET unit price (net is the anchor), the line total
     * is gross: side by side they read 7,42 € and 8,90 € for one piece. The
     * gross unit price, derived the way the invoice derives it (InvoiceView).
     *
     * @param array<string, mixed> $row
     */
    private function unitGross(array $row, string $currency): string
    {
        $unit = (string) ($row['unitPrice'] ?? '0');
        $rate = (string) ($row['taxRate'] ?? '0');

        if (!is_numeric($unit) || !is_numeric($rate)) {
            return $unit;
        }

        $digits = '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;

        return (string) BigDecimal::of($unit)
            ->multipliedBy(BigDecimal::of($rate)->plus(100))
            ->dividedBy(100, 8, RoundingMode::HalfUp)
            ->toScale($digits, RoundingMode::HalfUp);
    }

    private function currency(int $orderId): string
    {
        if (null === $this->connection || $orderId < 1) {
            return '';
        }

        return $this->currencies[$orderId] ??= (string) $this->connection->fetchOne('SELECT currency FROM tl_consho_order WHERE id = ?', [$orderId]);
    }

    private function amount(string $amount, string $currency): string
    {
        if (null === $this->money || '' === $currency || !is_numeric($amount)) {
            return StringUtil::specialchars($amount);
        }

        return $this->money->format($amount, $currency, $this->language());
    }

    private function quantity(string $quantity): string
    {
        if (null === $this->money || !is_numeric($quantity)) {
            return StringUtil::specialchars($quantity);
        }

        return $this->money->quantity($quantity, $this->language());
    }

    private function language(): string
    {
        return (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
    }

    /**
     * Released: where "Leistung erbracht" is (the delivery list). Withdrawn
     * and revoked: nothing, there is nothing left to render. Not released:
     * when it will be.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $lang
     */
    private function notReleasedNote(array $row, array $lang): string|null
    {
        if (null === $this->connection || null === $this->release) {
            return null;
        }

        $revoked = $this->connection->fetchFirstColumn('SELECT revoked FROM tl_consho_entitlement WHERE orderItem = ?', [(int) ($row['id'] ?? 0)]);

        if ([] !== $revoked) {
            return [] === array_filter($revoked, static fn (mixed $flag): bool => 0 === (int) $flag)
                ? null
                : (string) ($lang['performedInDeliveries'] ?? 'released');
        }

        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [(int) ($row['pid'] ?? 0)]);

        if (false === $order || null !== $order['cancelledAt']) {
            return null;
        }

        $dueAt = $this->release->dueAt($order);

        if (null === $dueAt) {
            return (string) ($lang['performedAfterPayment'] ?? 'awaiting payment');
        }

        if ($dueAt <= time()) {
            return (string) ($lang['performedReleaseDue'] ?? 'release due');
        }

        return \sprintf((string) ($lang['performedFrom'] ?? 'from %s'), Date::parse(Date::getNumericDateFormat(), $dueAt));
    }
}

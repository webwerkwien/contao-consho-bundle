<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Date;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode;

/**
 * The lists of vouchers and their bookings (block Wertgutscheine, WG13), in
 * the shape a person reads: the code in groups, money as money — a raw
 * decimal(12,4) would read "50.0000" (Prüfungsnotiz, Fall 30).
 */
final class VoucherListListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, mixed>    $args
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_voucher', target: 'list.label.label')]
    public function voucher(array $row, string $label, DataContainer $dc, array $args): array
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_voucher'] ?? [];
        $currency = (string) $row['currency'];
        $validUntil = $row['validUntil'] ?? null;

        return [
            VoucherCode::format((string) $row['code']),
            $this->money((string) $row['balance'], $currency),
            $this->money((string) $row['faceValue'], $currency),
            null === $validUntil || '' === $validUntil ? (string) ($lang['unlimited'] ?? '–') : Date::parse(Date::getNumericDateFormat(), (int) $validUntil),
            (string) ($lang['sources'][$row['source']] ?? $row['source']),
            (bool) $row['blocked'] ? (string) ($lang['blockedShort'] ?? '✕') : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_voucher_booking', target: 'list.sorting.child_record')]
    public function booking(array $row): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_voucher_booking'] ?? [];
        $currency = (string) $this->connection->fetchOne('SELECT currency FROM tl_consho_voucher WHERE id = ?', [(int) $row['pid']]);
        $order = (int) $row['orderId'] > 0 ? (string) $this->connection->fetchOne('SELECT orderNumber FROM tl_consho_order WHERE id = ?', [(int) $row['orderId']]) : '';

        return \sprintf(
            '%s · %s · %s → %s%s',
            Date::parse(Date::getNumericDatimFormat(), (int) $row['createdAt']),
            htmlspecialchars((string) ($lang['types'][$row['type']] ?? $row['type'])),
            $this->money((string) $row['amount'], $currency),
            $this->money((string) $row['balanceAfter'], $currency),
            '' === $order ? '' : ' · '.htmlspecialchars($order),
        );
    }

    /**
     * The head of the bookings: Contao prints the parent's columns raw — the
     * balance read "20.0000" at the acceptance of WG-2 (Fall 30 again).
     *
     * @param array<string, string> $labels field label => value, as Contao built them
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_voucher_booking', target: 'list.sorting.header')]
    public function header(array $labels): array
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_voucher'] ?? [];
        $codeLabel = (string) ($lang['code'][0] ?? 'code');
        $balanceLabel = (string) ($lang['balance'][0] ?? 'balance');
        $currency = (string) ($labels[(string) ($lang['currency'][0] ?? 'currency')] ?? '');

        if (isset($labels[$codeLabel])) {
            $labels[$codeLabel] = VoucherCode::format((string) $labels[$codeLabel]);
        }

        if (isset($labels[$balanceLabel]) && is_numeric($labels[$balanceLabel])) {
            $labels[$balanceLabel] = $this->money((string) $labels[$balanceLabel], $currency);
        }

        return $labels;
    }

    private function money(string $amount, string $currency): string
    {
        return '' === $currency ? $amount : $this->money->format($amount, $currency, (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de'));
    }
}

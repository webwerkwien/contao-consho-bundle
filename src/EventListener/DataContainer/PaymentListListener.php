<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Date;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The payments of an order in the back end, with amounts as money.
 *
 * Before Abo-5 the header said "Gesamtsumme 1.2000" and the row "1.2000
 * (Überweisung)" — the raw decimal(10,4), which MySQL returns with its
 * trailing zeros, on a list that had been there since Block D.
 */
class PaymentListListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_payment', target: 'list.label.label')]
    public function label(array $row): string
    {
        $currency = (string) $this->connection->fetchOne('SELECT currency FROM tl_consho_order WHERE id = ?', [(int) ($row['pid'] ?? 0)]);
        $receivedAt = (int) ($row['receivedAt'] ?? 0);
        $method = trim((string) ($row['method'] ?? ''));

        return implode(' — ', array_filter([
            $receivedAt > 0 ? Date::parse(Date::getNumericDatimFormat(), $receivedAt) : '',
            $this->amount((string) ($row['amount'] ?? '0'), $currency).('' !== $method ? ' ('.StringUtil::specialchars($method).')' : ''),
        ], static fn (string $part): bool => '' !== $part));
    }

    /**
     * The order's total in the header, formatted the same way.
     *
     * @param array<string, string> $labels header label => value, as DC_Table built them
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_payment', target: 'list.sorting.header')]
    public function header(array $labels, DataContainer $dc): array
    {
        $order = $this->connection->fetchAssociative('SELECT grandTotal, currency FROM tl_consho_order WHERE id = ?', [(int) $dc->id]);
        $key = $GLOBALS['TL_DCA']['tl_consho_order']['fields']['grandTotal']['label'][0]
            ?? $GLOBALS['TL_LANG']['tl_consho_order']['grandTotal'][0]
            ?? 'grandTotal';

        if (false !== $order && isset($labels[$key])) {
            $labels[$key] = $this->amount((string) $order['grandTotal'], (string) $order['currency']);
        }

        return $labels;
    }

    private function amount(string $amount, string $currency): string
    {
        // An order always has a currency; without one no symbol can be chosen.
        return '' === $currency ? $this->money->quantity($amount, $this->language()) : $this->money->format($amount, $currency, $this->language());
    }

    private function language(): string
    {
        return (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The positions of a withdrawal, in columns: product, declared, accepted,
 * returned, back in stock.
 *
 * Contao's own cells would show the raw decimal(10,4) ("1.0000"). A row the
 * operator added when accepting has no declared quantity — a dash, not a 0
 * the customer never said.
 */
class WithdrawalItemListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * ⚠️ An ARRAY — with showColumns Contao throws a string away.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_withdrawal_item', target: 'list.label.label')]
    public function label(array $row): array
    {
        $declared = (bool) ($row['declared'] ?? true);

        return [
            StringUtil::specialchars((string) $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE id = ?', [(int) ($row['orderItem'] ?? 0)])),
            $declared ? $this->quantity($row['quantity'] ?? '0') : '–',
            $this->quantity($row['acceptedQuantity'] ?? '0'),
            $this->quantity($row['returnedQuantity'] ?? '0'),
            $this->quantity($row['restockedQuantity'] ?? '0'),
        ];
    }

    private function quantity(mixed $value): string
    {
        $value = (string) $value;

        return is_numeric($value) ? $this->money->quantity($value, (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de')) : '';
    }
}

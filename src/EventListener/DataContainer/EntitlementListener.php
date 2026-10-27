<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\Date;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The row of an entitlement in the back end (F-4). The plain label format said
 * "4 × 1.0000 — …" — the article's id and a raw decimal(10,4), which MySQL
 * returns with its trailing zeros. Now the name
 * frozen on the order line, the quantity as a person writes it, the end or
 * "dauerhaft", and "gesperrt" where it is.
 */
class EntitlementListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_entitlement', target: 'list.label.label')]
    public function label(array $row): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_entitlement'] ?? [];
        $name = (string) $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE id = ?', [(int) ($row['orderItem'] ?? 0)]);
        $until = null === ($row['validUntil'] ?? null) || '' === (string) $row['validUntil']
            ? (string) ($lang['permanent'] ?? 'permanent')
            : \sprintf((string) ($lang['until'] ?? 'until %s'), Date::parse(Date::getNumericDateFormat(), (int) $row['validUntil']));

        $parts = [
            \sprintf('%s × %s', $this->money->quantity((string) ($row['quantity'] ?? '0'), (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de')), StringUtil::specialchars($name)),
            $until,
        ];

        if ((bool) ($row['revoked'] ?? false)) {
            $parts[] = '<span class="tl_red">'.($lang['revokedLabel'] ?? 'revoked').'</span>';
        }

        if (null !== ($row['suspendedAt'] ?? null) && '' !== (string) $row['suspendedAt']) {
            $parts[] = '<span class="tl_red">'.\sprintf((string) ($lang['suspendedLabel'] ?? 'resting since %s'), Date::parse(Date::getNumericDateFormat(), (int) $row['suspendedAt'])).'</span>';
        }

        return implode(' — ', $parts);
    }
}

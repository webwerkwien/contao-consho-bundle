<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\Date;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * The kind of a product line in the order's line list (DL4, spec 6.4), and
 * when a service was reported as rendered — what "Leistung erbracht" is
 * offered for, and what it did, without opening the line.
 *
 * Charge lines carry no kind, and get nothing added.
 */
class OrderItemLabelListener
{
    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_order_item', target: 'list.label.label')]
    public function __invoke(array $row, string $label): string
    {
        if ('product' !== (string) ($row['lineType'] ?? '')) {
            return $label;
        }

        $lang = $GLOBALS['TL_LANG']['tl_consho_order_item'] ?? [];
        $kind = ProductKind::stored((string) ($row['kind'] ?? ''));
        $parts = [(string) ($lang['kinds'][$kind->value] ?? $kind->value)];

        if (null !== ($row['performedAt'] ?? null) && '' !== (string) $row['performedAt']) {
            $parts[] = \sprintf((string) ($lang['performedOn'] ?? 'performed on %s'), Date::parse(Date::getNumericDateFormat(), (int) $row['performedAt']));
        }

        return $label.' <span class="label-info">['.implode(', ', $parts).']</span>';
    }
}

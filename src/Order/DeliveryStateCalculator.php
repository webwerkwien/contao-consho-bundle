<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;

/**
 * Delivery state follows from the delivery records.
 *
 * Partial delivery therefore needs no separate concept — it is simply the case
 * where some quantities have shipped and others have not. Isotope has no
 * partial delivery at all; here it is a by-product of the data model rather
 * than a feature anyone had to build.
 */
final class DeliveryStateCalculator
{
    /**
     * @param array<int|string, string> $ordered   order item id => quantity ordered
     * @param array<int|string, string> $delivered order item id => quantity shipped so far
     */
    public function calculate(array $ordered, array $delivered): DeliveryState
    {
        // An order with nothing to ship — digital goods, say — is not pending.
        if ([] === $ordered) {
            return DeliveryState::Complete;
        }

        $anyDelivered = false;
        $allDelivered = true;

        foreach ($ordered as $itemId => $quantity) {
            $shipped = BigDecimal::of($delivered[$itemId] ?? '0');

            if ($shipped->isPositive()) {
                $anyDelivered = true;
            }

            // Not isEqualTo: sending a replacement alongside must not leave the
            // order stuck at "partial" forever.
            if ($shipped->isLessThan(BigDecimal::of($quantity))) {
                $allDelivered = false;
            }
        }

        if ($allDelivered) {
            return DeliveryState::Complete;
        }

        return $anyDelivered ? DeliveryState::Partial : DeliveryState::Open;
    }
}

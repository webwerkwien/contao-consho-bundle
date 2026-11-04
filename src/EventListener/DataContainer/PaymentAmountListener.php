<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * A payment typed in the back end is money that came in (Geldflüsse E5).
 *
 * A negative row typed by hand was a refund no other part knew of: tied to no
 * payment, so "Erstatten" offered the money again, and no refund made at the
 * provider could be assigned to it. Money goes back through "Erstatten", a
 * withdrawal or a cancellation, which book it so that every part sees it.
 *
 * Only what the operator types: a row already negative — written by Consho
 * itself and not yet locked — keeps its amount.
 */
class PaymentAmountListener
{
    #[AsCallback(table: 'tl_consho_payment', target: 'fields.amount.save')]
    public function __invoke(mixed $value, DataContainer $dc): mixed
    {
        try {
            $amount = BigDecimal::of(str_replace(',', '.', trim((string) $value)));
            $stored = BigDecimal::of((string) ($dc->getCurrentRecord()['amount'] ?? '0'));
        } catch (MathException) {
            // Not a number: the field's own check (rgxp digit) answers.
            return $value;
        }

        if ($stored->isNegative() && $amount->isEqualTo($stored)) {
            return $value;
        }

        if (!$amount->isPositive()) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_payment']['amountNotPositive'] ?? 'A payment entered here is money received: enter an amount above 0.'));
        }

        return $value;
    }
}

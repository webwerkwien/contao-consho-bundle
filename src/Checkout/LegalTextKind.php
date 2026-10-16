<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * The two texts a checkout freezes (5.1, `tl_consho_order_legal.kind`).
 *
 * Exactly two, and no way to add a third from the back end: each one has a
 * field of its own on the root page and a place of its own in the checkout and
 * in the confirmation mail. A configurable list would be a list nothing reads.
 */
enum LegalTextKind: string
{
    case Terms = 'terms';
    case Withdrawal = 'withdrawal';

    /**
     * The field on `tl_page` that points at this text's element.
     */
    public function field(): string
    {
        return match ($this) {
            self::Terms => 'consho_termsElement',
            self::Withdrawal => 'consho_withdrawalElement',
        };
    }
}

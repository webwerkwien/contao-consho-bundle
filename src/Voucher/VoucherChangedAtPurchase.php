<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

/**
 * A voucher could no longer pay what the review page showed (block
 * Wertgutscheine, E4): spent in another basket, blocked or run out between
 * the page and the click. The order is not placed — the transaction rolls
 * back — and the customer is told.
 */
final class VoucherChangedAtPurchase extends \RuntimeException
{
    public static function for(int $voucherId): self
    {
        return new self(\sprintf('Voucher %d can no longer pay what the checkout showed.', $voucherId));
    }
}

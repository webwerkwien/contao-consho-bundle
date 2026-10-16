<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * Why a buy click did not become an order (7.3, step 1).
 *
 * Separate from `CheckoutProblem`, which describes a state that cannot be
 * bought from at all. These are about the click itself: the state was fine,
 * the submission was not.
 *
 * The value travels in the address, like the basket's refusals do since C-3c —
 * a POST that ends in a redirect cannot carry anything else, and a reload of
 * the result must not order.
 */
enum PurchaseProblem: string
{
    /**
     * The state changed between the page being rendered and the button being
     * pressed — a second tab, a price change, a shipping method withdrawn.
     *
     * Never corrected silently: what stood above the button is what § 8 FAGG
     * asked the buyer to agree to.
     */
    case ChecksumMismatch = 'checksumMismatch';

    /**
     * The shop asks for the terms to be ticked (C18) and they were not.
     */
    case TermsNotAccepted = 'termsNotAccepted';

    /**
     * The start point names no order page, so there would be nowhere to send
     * the buyer afterwards (C-7).
     *
     * CheckoutReadiness blocks such a shop before the element renders at all,
     * so this is the belt to that braces. Placing the order anyway and then
     * having no confirmation to show is the one outcome worth avoiding twice.
     */
    case NoOrderPage = 'noOrderPage';

    /**
     * The order could not be placed, and the reason is not the buyer's to fix
     * (C-7): the payment method disappeared between the review step and the
     * click, the basket was cleaned up underneath it, or the total no longer
     * agrees (a payment fee without a tax choice left this list in E-1: the
     * calculator no longer offers such a method at all).
     *
     * One case for all of them on purpose. They differ in what an OPERATOR has
     * to do — and that is in the log with the exception that carries it, not
     * in a sentence a customer reads. Told apart on the page, they would all
     * say the same thing anyway: not now, and not because of you.
     */
    case CouldNotPlace = 'couldNotPlace';

    /**
     * An article was no longer there in the ordered quantity at the click
     * (Block G, G5): the basket holds nothing back, and someone else bought
     * the last piece first. Unlike CouldNotPlace this is the buyer's to fix —
     * the basket shows how many are left.
     */
    case StockShort = 'stockShort';

    /**
     * The discount's last use went to another order between the review
     * page and the click (block H, H-4). Nothing was ordered; the basket
     * says the code is used up, and the buyer decides without it.
     */
    case DiscountExhausted = 'discountExhausted';

    /**
     * A voucher could no longer pay what the review page showed (block
     * Wertgutscheine, E4): spent elsewhere, blocked or expired in between.
     * Nothing was ordered; the basket shows what the vouchers hold now.
     */
    case VoucherChanged = 'voucherChanged';
}

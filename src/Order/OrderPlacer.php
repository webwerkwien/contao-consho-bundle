<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Webwerkwien\ContaoConshoBundle\Cart\CartConversionInterface;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalLegalTextSource;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalTextKind;
use Webwerkwien\ContaoConshoBundle\Checkout\ShopLegalRequirements;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Stock\StockAllocation;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRecorderInterface;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherRedemption;

/**
 * The purchase click, as one transaction (spec 7.3 step 2).
 *
 * Everything that has to be true together is written together: the order and
 * its lines, the legal texts the buyer read, the view token, the invoice if
 * the method issues one on order, and the basket marked as converted. A crash
 * anywhere in there leaves nothing behind.
 *
 * ── Why a transaction and not just careful ordering ──────────────────────
 *
 * Until C-7 OrderFactory wrote the head and then the lines, one statement at a
 * time. An abort in between left an order WITHOUT ITS LINES and with an order
 * number spent — and an order number that is gone is gone, because the
 * allocator only counts up.
 *
 * ── The number range rides along ─────────────────────────────────────────
 *
 * NumberRangeAllocator opens its own transaction with FOR UPDATE, and DBAL
 * 4.4 nests transactions EXCLUSIVELY through savepoints (support for nesting
 * without them was removed; the configuration for it is deprecated because it
 * is no longer configurable). So the allocator's commit releases a savepoint
 * rather than the transaction: roll the outer one back and the counter rolls
 * back with it. No gap in the invoice numbers.
 *
 * The flip side is the reason the PDF is NOT rendered here: the lock on the
 * number range is held until the outer transaction ends, and dompdf takes
 * seconds during which no other order in this shop could get a number. The
 * snapshot is enough (C-8).
 *
 * ── What deliberately happens after the commit ───────────────────────────
 *
 * Storing the PDF (C-8), the confirmation mail (C-9) and the handler's
 * start() — none of them may undo an order that exists. A mail server that is
 * down is not a reason to lose a purchase.
 *
 * ── And no payment attempt here (F5) ─────────────────────────────────────
 *
 * Spec 7.3 step 2 put the first attempt in this transaction, and C-7 wrote one
 * with a token that then existed nowhere: PlacedOrder carried the plaintext
 * out of the request, no caller read it, and findByHash() could never find the
 * row again. What it recorded — method, title, amount, currency — the order
 * head already carries in paymentMethod, paymentMethodTitle, grandTotal and
 * currency. So it documented nothing and left a row in `open` for
 * PaymentStateCalculator and for 7.5's "cancel the open attempts" to reason
 * about.
 *
 * An attempt is now opened where a payment actually begins, by start()
 * (C-10/C-11). That caller holds the plaintext while it builds the return and
 * notify URLs, which is the only moment the token is of any use.
 */
final class OrderPlacer implements OrderPlacerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CartConversionInterface $carts,
        private readonly OrderFactoryInterface $orders,
        private readonly DbalLegalTextSource $legalTexts,
        private readonly OrderLegalWriterInterface $legalWriter,
        private readonly TokenGenerator $tokens,
        private readonly DocumentIssuerInterface $documents,
        private readonly PaymentHandlers $handlers,
        private readonly DocumentArchiverInterface $archiver,
        private readonly ShopLegalRequirements $requirements,
        // Required, not nullable (Abo-3): an optional one would be missing in
        // silence, and every subscription bought would be one nobody records.
        private readonly SubscriptionRecorderInterface $subscriptions,
        // Block G (G4): required for the same reason.
        private readonly StockAllocation $stock,
        // Block Wertgutscheine (WG15): required for the same reason — an
        // order of 0 € would otherwise never count as paid.
        private readonly PurchaseSettlement $settlement,
        // Block Wertgutscheine (WG-5): required likewise — an order whose
        // vouchers nobody books would be paid with money that stays put.
        private readonly VoucherRedemption $vouchers,
        // Block H (H-4): counts the use of a cart rule. Optional for the tests.
        private readonly CartDiscounts|null $discounts = null,
    ) {
    }

    /**
     * @param list<OrderLineDraft> $lines
     * @param int                  $rootId the start point, which is where the legal texts hang
     *
     * @throws ProductGoneFromCatalogue
     * @throws StockShortAtPurchase
     */
    public function place(
        int $cartId,
        int $rootId,
        OrderDraft $draft,
        array $lines,
        PaymentMethod $method,
        int $now,
    ): PlacedOrder {
        /*
         * READ COMMITTED for the purchase (Block G): a purchase that
         * waited for the lock on a key pool's stock row must count the codes
         * the one before it took. Under MySQL's default REPEATABLE READ its
         * plain reads would still see the snapshot from before the wait; the
         * locking reads that would see past it deadlock with every concurrent
         * purchase. Locking reads elsewhere in the click
         * (basket, number range, stock) behave the same under both.
         *
         * Only where no transaction is open: the level cannot change inside one.
         */
        $previous = null;

        if (!$this->connection->isTransactionActive()) {
            $previous = $this->connection->getTransactionIsolation();
            $this->connection->setTransactionIsolation(TransactionIsolationLevel::READ_COMMITTED);
        }

        try {
            return $this->placeInTransaction($cartId, $rootId, $draft, $lines, $method, $now);
        } finally {
            if (null !== $previous) {
                $this->connection->setTransactionIsolation($previous);
            }
        }
    }

    /**
     * @param list<OrderLineDraft> $lines
     *
     * @throws ProductGoneFromCatalogue
     * @throws StockShortAtPurchase
     * @throws \Webwerkwien\ContaoConshoBundle\Voucher\VoucherChangedAtPurchase
     */
    private function placeInTransaction(int $cartId, int $rootId, OrderDraft $draft, array $lines, PaymentMethod $method, int $now): PlacedOrder
    {
        $this->connection->beginTransaction();

        try {
            $cart = $this->carts->lockForPurchase($cartId);

            if (null === $cart) {
                $this->connection->rollBack();

                throw BasketGoneAtPurchase::withId($cartId);
            }

            /*
             * The double click (7.3 step 3). The second request waited on the
             * lock above; by now the first has committed and the basket says
             * converted. It gets a view token of its own for the SAME order —
             * a token cannot be read back out of the database, and issuing one
             * is cheaper than any of the alternatives. No second order.
             */
            if ('converted' === $cart['state'] && $cart['order'] > 0) {
                $placed = new PlacedOrder(
                    $cart['order'],
                    $this->tokens->issue($cart['order'], TokenPurpose::ViewOrder),
                    true,
                );

                $this->connection->commit();

                return $placed;
            }

            $orderId = $this->orders->create($draft, $lines, $now);

            /*
             * Abo-3: one subscription per subscription line, from the lines
             * just frozen. In this transaction, so there is never an order
             * with a subscription line and no subscription to cancel.
             */
            $this->subscriptions->record($orderId);

            /*
             * Block G (G4): the goods out of stock, in this transaction. Short
             * anywhere, and the order rolls back with every booking before it
             * — the basket holds nothing back (G5), the click decides.
             */
            $this->stock->bookOrder($orderId, $now);

            /*
             * Block H (H-4): the cart rule's use, counted under the lock of
             * its row — the last use goes to one order, the other click
             * rolls back with everything before it (W5).
             */
            if ($draft->discountRule > 0) {
                $this->discounts?->redeem($draft->discountRule, $draft->discountCode, $orderId, $draft->memberId, $draft->billing->email, $now);
            }

            /*
             * What the buyer read, frozen (C-17). Read from the start point,
             * not from the form: the checkout showed these very elements, and
             * a text that arrived in the request would be the buyer's word for
             * what the shop said.
             *
             * The REQUIRED kinds have to be there (ShopLegalRequirements, D-2
             * E1 — until then both were, always, and a shop without terms
             * could not sell at all). The checksum already refuses a purchase
             * where a text has changed or disappeared since the page was
             * rendered (F3), and this closes the sliver between that check and
             * this read: an element whose time window ends in those
             * milliseconds would otherwise produce an order carrying a ticked
             * box and no wording — the one shape of record that proves
             * nothing.
             *
             * Every text that IS there is frozen, required or not: it is what
             * the buyer was shown.
             */
            $texts = $this->legalTexts->forRoot($rootId);

            foreach ($this->requirements->forShop($draft->shopId) as $kind) {
                if (!isset($texts[$kind->value])) {
                    throw LegalTextGoneAtPurchase::forKind($kind->value);
                }
            }

            foreach (LegalTextKind::cases() as $kind) {
                if (isset($texts[$kind->value])) {
                    $this->legalWriter->freeze($orderId, $texts[$kind->value], $now);
                }
            }

            /*
             * Two tokens for one page (C-10). The redirect after this click
             * and the link in the confirmation used to share one, so a click
             * on the order page right after buying "confirmed" an address
             * whose mail nobody had opened. Only
             * the mail's token proves the mailbox.
             */
            $viewToken = $this->tokens->issue($orderId, TokenPurpose::ViewOrder);
            $mailToken = $this->tokens->issue($orderId, TokenPurpose::ViewOrder, null, true);

            /*
             * Prepayment issues its invoice on order; a card payment waits for
             * the money (C-6). issueOnce, not issue: a later trigger must not
             * produce a second invoice for the same order.
             */
            /*
             * WG-5: the vouchers pay, under the lock of each (E4) — before
             * the invoice, so a prepayment invoice states them and asks only
             * for the rest (WG12).
             */
            $this->vouchers->redeem($orderId, $draft, $now);

            $invoiceId = null;

            // "By voucher" (E1) has no handler: it is paid by the click, below.
            if ($this->handlers->has($method->type) && DocumentTrigger::OnOrder === $this->handlers->get($method->type)->documentTrigger($method->config)) {
                $invoiceId = $this->documents->issueOnce($orderId, $draft->shopId, DocumentType::Invoice);
            }

            // WG15: nothing to pay — paid by the click (PurchaseSettlement).
            $invoiceId = $this->settlement->settleIfNothingOpen($orderId, $draft->shopId, $method, $now) ?? $invoiceId;

            $this->carts->markConverted($cartId, $orderId, $now);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        /*
         * The PDF for the archive, now that the number range is released
         * (C-8). archive() never throws; a failure is recorded on the
         * document, and the order stands.
         */
        if (null !== $invoiceId) {
            $this->archiver->archive($invoiceId);
        }

        return new PlacedOrder($orderId, $viewToken, false, $mailToken);
    }
}

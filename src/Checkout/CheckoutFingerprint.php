<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Webwerkwien\ContaoConshoBundle\Address\Address;

/**
 * A checksum over everything the buyer agreed to when they pressed the button
 * (7.3, step 1).
 *
 * The form carries it as a hidden field; the buy click recalculates the state
 * and compares. Differ, and nothing is ordered — the step is shown again with
 * a notice. **Never a silent correction:** somebody who saw €13.90 and was
 * charged €15.40 was not asked, and § 8 FAGG wants exactly the figures that
 * stood above the button.
 *
 * This only works because `CheckoutCalculator` has no side effects: the second
 * calculation may not change anything the first one saw. That promise was made
 * in C-5 without a taker; this is the taker.
 *
 * What is deliberately NOT in the checksum:
 *
 * - **Timestamps of any kind**, including `vatCheck`, which carries the moment
 *   of the VIES query. A check that ran again while the buyer was reading is
 *   the same agreement.
 * - **`termsAcceptedAt`**, because the tick box is on the very form the
 *   checksum travels in. Including it would make every tick invalidate the
 *   checksum it was sent with.
 * - **Display texts** — method titles, formatted amounts, translations. A
 *   corrected typo in a shipping method's title is not a change to the deal.
 *   The method's id and its amount are in, and those are what would change it.
 *
 * ── The legal texts ARE in ───────────────────────────────────────────────
 *
 * They are not display texts. The terms and the withdrawal instruction are
 * what the buyer agreed to, and OrderPlacer freezes them at the click by
 * reading the elements again (C-17). Between the page being rendered and the
 * button being pressed, an operator can save a new version — or a scheduled
 * one can become visible at a minute boundary. The buyer then ticked v1 and
 * the order records v2 with their timestamp beside it, which is exactly the
 * case the snapshot exists for.
 *
 * The controller had once written the gap
 * down as "belongs with the freezing itself (C-7)" and C-7 had not closed it.
 */
class CheckoutFingerprint
{
    /**
     * @param array<string, string> $legalChecksums kind => checksum, as
     *                                              DbalLegalTextSource hands them out
     */
    public function of(CheckoutState $state, array $legalChecksums = []): string
    {
        return hash('sha256', implode("\0", $this->parts($state, $legalChecksums)));
    }

    /**
     * @param array<string, string> $legalChecksums
     */
    public function matches(CheckoutState $state, string $claimed, array $legalChecksums = []): bool
    {
        // hash_equals, not ===: the checksum comes from the request, and a
        // timing-safe comparison costs nothing here.
        return '' !== $claimed && hash_equals($this->of($state, $legalChecksums), $claimed);
    }

    /**
     * @param array<string, string> $legalChecksums
     *
     * @return list<string>
     */
    private function parts(CheckoutState $state, array $legalChecksums): array
    {
        $parts = [
            'v1',
            $state->currency,
            $state->netTotal,
            $state->grossTotal,
            $state->reverseCharge ? '1' : '0',
            (string) $state->minimumAge,
            $state->needsShipping ? '1' : '0',
        ];

        /*
         * The withdrawal kinds the consent box was worded after (Abo-2, P14).
         * The order freezes the kind at the click; switched in between, the
         * confirmation would record a sentence the buyer never read — the
         * same gap the legal texts closed (Abo-2). Only written when there is one, so a basket without digital
         * lines keeps the checksum it had before.
         */
        if ([] !== $state->withdrawalKinds) {
            $parts[] = 'w:'.implode(',', $state->withdrawalKinds);
        }

        /*
         * Block H (H-4): which cart rule applies, under which code, and what
         * it takes per class. The totals alone would not do: another rule
         * worth the same, or a code taken away and another typed, would leave
         * them equal and freeze a different discount line. Only written when
         * there is one, like the plan and the withdrawal kinds.
         */
        if (null !== $state->discount) {
            $parts[] = 'd:'.$state->discount->ruleId.'|'.$state->discount->code.'|'.($state->discount->freeShipping ? 'f' : '')
                .'|'.implode(',', array_map(static fn (ShippingShare $share): string => $share->taxClassId.':'.$share->net, $state->discountShares));
        }

        /*
         * Block Wertgutscheine (WG-5): which vouchers pay how much. The total
         * stays the same whether a voucher pays 20 or 0 of it, and "noch zu
         * zahlen" is what the buyer agreed to hand over. A voucher spent in
         * another basket between page and click changes this part, and the
         * click refuses (E4). Only written when one pays, like the discount.
         */
        if ([] !== $state->payingVouchers()) {
            $parts[] = 'g:'.implode(',', array_map(static fn ($voucher): string => $voucher->id.':'.$voucher->applied, $state->payingVouchers()));
        }

        foreach ($state->cart->lines as $line) {
            $parts[] = implode('|', [
                $line->articleId,
                $line->quantity,
                $line->unitNet,
                $line->lineNet,
                $line->lineGross,
                $line->taxClassId,
                /*
                 * The plan (Abo-2): the same amount can be a different
                 * contract — a monthly plan at the one-time price, say. Only
                 * written when there is one, so a one-time line keeps the
                 * checksum it had before and no open checkout is refused at
                 * the deploy.
                 */
                ...($line->subscriptionRule > 0 ? ['r'.$line->subscriptionRule] : []),
                // Abo-7b: which subscription a line renews — a
                // renewal and a new subscription of the same plan are two
                // contracts at one price. Written the same way, only if set.
                ...($line->renews > 0 ? ['n'.$line->renews] : []),
            ]);
        }

        // The selected methods by id and amount. A method that is gone shows
        // up as "0||" and so differs from every real selection.
        $parts[] = $this->methodPart($state->shipping);
        $parts[] = $this->methodPart($state->payment);

        $parts[] = $this->addressPart($state->data->billing);
        $parts[] = $state->data->separateShipping ? $this->addressPart($state->data->shipping) : 'same';

        // The VAT id itself, not the check result: whether the number was
        // accepted shows in reverseCharge and in the totals above.
        $parts[] = $state->data->vatId;
        $parts[] = $state->data->dateOfBirth;
        // Only where the carrier asks for it (E-6) — the same phone that goes
        // onto the order.
        $parts[] = $state->needsPhone ? $state->data->shippingPhone : '';

        /*
         * The wording the buyer read, by its checksum. Sorted by kind so that
         * the order the source happened to return them in cannot change the
         * result — the same two texts have to produce the same checksum.
         *
         * A text that is MISSING is a change too: the part then reads
         * "terms=" and differs from every version that existed.
         */
        ksort($legalChecksums);

        foreach ($legalChecksums as $kind => $checksum) {
            $parts[] = 'legal:'.$kind.'='.$checksum;
        }

        return $parts;
    }

    private function methodPart(MethodOption|null $method): string
    {
        return null === $method ? '0||' : implode('|', [$method->id, $method->net, $method->gross]);
    }

    private function addressPart(Address $address): string
    {
        return implode('|', [
            $address->company,
            $address->salutation,
            $address->firstname,
            $address->lastname,
            $address->street,
            $address->postal,
            $address->city,
            $address->state,
            $address->country,
            $address->phone,
            $address->email,
        ]);
    }
}

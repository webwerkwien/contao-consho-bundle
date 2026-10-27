<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Money back onto vouchers, and vouchers taken back (block Wertgutscheine,
 * 6.6, WG7, WG11, D1, D2).
 *
 * What a voucher paid for an order is read from its bookings, not from the
 * payment's reference: the anonymisation empties the reference, the
 * bookings keep the order (the voucher is a bearer instrument and carries
 * no person).
 *
 * A refund goes onto the vouchers first, each up to what it paid (§ 14
 * Abs. 1 FAGG, § 357 Abs. 3 BGB: the same means of payment), as a negative
 * payment on the order and a `refund` booking pointing at it. An expired
 * voucher is unlimited again (D1); one still valid keeps its date.
 *
 * Runs inside the caller's transaction (the withdrawal's order lock, the
 * status writer's cancellation); VoucherLedger nests as a savepoint.
 */
class VoucherReturn
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VoucherLedger $ledger,
        private readonly TranslatorInterface|null $translator = null,
    ) {
    }

    /**
     * What each voucher paid for the order and has not had back, in the
     * order they paid.
     *
     * A BLOCKED voucher is left out (WG-7): money booked back onto it could never be spent
     * again. Its share goes to the payment method instead, and the operator
     * pays it out — nothing is lost.
     *
     * @return array<int, BigDecimal> voucher id => amount, positive only
     */
    public function paidBy(int $orderId): array
    {
        $paid = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT b.pid, SUM(b.amount) AS amount, MIN(b.id) AS first FROM tl_consho_voucher_booking b
               JOIN tl_consho_voucher v ON v.id = b.pid AND v.blocked = 0
              WHERE b.orderId = ? AND b.type IN ('redeem', 'refund')
              GROUP BY b.pid ORDER BY first",
            [$orderId],
        ) as $row) {
            $amount = BigDecimal::of((string) $row['amount'])->negated();

            if ($amount->isPositive()) {
                $paid[(int) $row['pid']] = $amount->toScale(4, RoundingMode::HalfUp);
            }
        }

        return $paid;
    }

    public function paidTotal(int $orderId): BigDecimal
    {
        $total = BigDecimal::zero()->toScale(4);

        foreach ($this->paidBy($orderId) as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * Books $amount back onto the order's vouchers, in the order they paid,
     * each up to what it paid. Never more than they paid altogether.
     *
     * With the credited lines of a withdrawal, a single-purpose voucher gets
     * back no more than those lines of its pot (EZ-4) — see plan().
     *
     * @param list<array<string, string>>|null $lines
     *
     * @return list<int> the payment ids
     */
    public function refund(int $orderId, string $amount, int $now, string $reference = '', string $language = '', array|null $lines = null): array
    {
        $payments = [];

        foreach ($this->plan($orderId, $amount, $lines) as ['voucher' => $voucherId, 'code' => $code, 'back' => $back, 'prepaid' => $prepaid]) {
            $this->connection->insert('tl_consho_payment', [
                'pid' => $orderId,
                'tstamp' => $now,
                'receivedAt' => $now,
                'amount' => (string) $back->negated(),
                'method' => mb_substr($this->label($code, $language), 0, 64),
                'reference' => 'voucher:'.$voucherId,
                'lockedAt' => $now,
                // EZ-4: the tax of a single-purpose voucher goes back with its money.
                'prepaidTaxRate' => $prepaid['rate'] ?? '0.0000',
                'prepaidTaxCountry' => $prepaid['country'] ?? '',
                'prepaidTax' => null === $prepaid ? '0.0000' : (string) $prepaid['tax']->negated(),
            ]);

            $paymentId = (int) $this->connection->lastInsertId();
            $payments[] = $paymentId;

            $this->ledger->book($voucherId, (string) $back, VoucherBookingType::Refund, $now, $orderId, $paymentId, 0, $reference);

            // D1: an expired voucher is unlimited again; a valid one keeps its date.
            $validUntil = $this->connection->fetchOne('SELECT validUntil FROM tl_consho_voucher WHERE id = ?', [$voucherId]);

            if (VoucherWallet::expired($validUntil, $now)) {
                $this->connection->update('tl_consho_voucher', ['validUntil' => null, 'tstamp' => $now], ['id' => $voucherId]);
            }
        }

        return $payments;
    }

    /**
     * What refund() would book back onto single-purpose vouchers (EZ-4), as
     * the snapshot's `prepaid` lists it — negative: the invoice correction
     * takes back the deduction for what goes back onto them. Issued before
     * the refund is booked; both run under the order's lock in one
     * transaction, so both read the same state and plan the same.
     *
     * @param list<array<string, string>>|null $lines
     *
     * @return list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}>
     */
    public function prepaidFor(int $orderId, string $amount, string $language = '', array|null $lines = null): array
    {
        $prepaid = [];

        foreach ($this->plan($orderId, $amount, $lines) as $step) {
            if (null === $step['prepaid']) {
                continue;
            }

            $prepaid[] = [
                'label' => mb_substr($this->label($step['code'], $language), 0, 64),
                'country' => $step['prepaid']['country'],
                'rate' => $step['prepaid']['rate'],
                'net' => (string) $step['back']->minus($step['prepaid']['tax'])->negated()->toScale(4),
                'tax' => (string) $step['prepaid']['tax']->negated()->toScale(4),
                'gross' => (string) $step['back']->negated()->toScale(4),
            ];
        }

        return $prepaid;
    }

    /**
     * What refund() would book onto the vouchers altogether — with the
     * lines of a withdrawal possibly less than asked; the rest goes to the
     * payment method.
     *
     * @param list<array<string, string>>|null $lines
     */
    public function onVouchers(int $orderId, string $amount, array|null $lines = null): BigDecimal
    {
        $total = BigDecimal::zero()->toScale(4);

        foreach ($this->plan($orderId, $amount, $lines) as $step) {
            $total = $total->plus($step['back']);
        }

        return $total;
    }

    /**
     * Which voucher gets how much back, in the order they paid, each up to
     * what it paid — and for a single-purpose one the tax that goes with it:
     * its share of the tax still standing on the order's payments by it,
     * all of it when all goes back, so no cent of rounding stays behind.
     *
     * With $lines (the credited lines of a withdrawal), a single-purpose
     * voucher gets back no more than what those lines credit in its pot —
     * its tax class, country and rate; returned discounts at that country
     * and rate count against it (EZ-4, F2). Otherwise a withdrawal of an
     * article it never paid for would take back tax at a rate the correction
     * does not carry. What it cannot take goes on to the next voucher and in
     * the end to the payment method. Without $lines (a cancellation) all of
     * it goes back.
     *
     * Read from the payments by reference: a voucher whose payments lost it
     * to the anonymisation gets its money back without the tax.
     *
     * @param list<array<string, string>>|null $lines
     *
     * @return list<array{voucher: int, code: string, back: BigDecimal, prepaid: array{rate: string, country: string, tax: BigDecimal}|null}>
     */
    private function plan(int $orderId, string $amount, array|null $lines = null): array
    {
        $left = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);
        $plan = [];
        $digits = null;
        $caps = null === $lines ? null : self::caps($lines);

        foreach ($this->paidBy($orderId) as $voucherId => $paid) {
            if (!$left->isPositive()) {
                break;
            }

            $voucher = $this->connection->fetchAssociative('SELECT code, taxClass, taxRate, taxCountry FROM tl_consho_voucher WHERE id = ?', [$voucherId]) ?: [];
            $code = (string) ($voucher['code'] ?? '');
            $back = BigDecimal::min($left, $paid);
            $pot = null;

            if (null !== $caps && (int) ($voucher['taxClass'] ?? 0) > 0) {
                $pot = self::pot((int) $voucher['taxClass'], (string) ($voucher['taxCountry'] ?? ''), (string) ($voucher['taxRate'] ?? '0'));
                $back = BigDecimal::min($back, BigDecimal::max(BigDecimal::zero(), $caps[$pot] ?? BigDecimal::zero()));

                if (!$back->isPositive()) {
                    continue;
                }

                $caps[$pot] = ($caps[$pot] ?? BigDecimal::zero())->minus($back);
            }

            $left = $left->minus($back);

            $standing = $this->connection->fetchAssociative(
                'SELECT SUM(amount) AS amount, SUM(prepaidTax) AS tax, MAX(prepaidTaxRate) AS rate, MAX(prepaidTaxCountry) AS country
                   FROM tl_consho_payment WHERE pid = ? AND reference = ? AND prepaidTaxRate > 0',
                [$orderId, 'voucher:'.$voucherId],
            );
            $prepaid = null;

            if (false !== $standing && null !== $standing['amount'] && BigDecimal::of((string) $standing['amount'])->isPositive()) {
                $digits ??= $this->digits($orderId);
                $standingAmount = BigDecimal::of((string) $standing['amount']);
                $standingTax = BigDecimal::of((string) $standing['tax']);
                $standingNet = $standingAmount->minus($standingTax);

                // The share's net is rounded and its tax is the rest, as a
                // line is split (SinglePurpose::taxIn()); the whole return
                // takes what stands, so the shares add up to it.
                $prepaid = [
                    'rate' => (string) BigDecimal::of((string) $standing['rate'])->toScale(4, RoundingMode::HalfUp),
                    'country' => strtoupper((string) $standing['country']),
                    'tax' => $back->isGreaterThanOrEqualTo($standingAmount)
                        ? $standingTax->toScale(4, RoundingMode::HalfUp)
                        : $back->minus($standingNet->multipliedBy($back)->dividedBy($standingAmount, $digits, RoundingMode::HalfUp))->toScale(4),
                ];
            }

            $plan[] = ['voucher' => $voucherId, 'code' => $code, 'back' => $back->toScale(4), 'prepaid' => $prepaid];
        }

        return $plan;
    }

    /**
     * Per pot what the credited lines give back: the articles (not a sold
     * voucher, not shipping or a fee) by class, country and rate, less the
     * discounts returned at that country and rate.
     *
     * @param list<array<string, string>> $lines amounts negative for a credit
     *
     * @return array<string, BigDecimal>
     */
    private static function caps(array $lines): array
    {
        $caps = [];
        $discounts = [];

        foreach ($lines as $line) {
            $credit = BigDecimal::of('' !== ($line['lineTotal'] ?? '') ? $line['lineTotal'] : '0')->negated();
            $country = strtoupper((string) ($line['taxCountry'] ?? ''));
            $rate = (string) BigDecimal::of('' !== ($line['taxRate'] ?? '') ? $line['taxRate'] : '0')->toScale(4, RoundingMode::HalfUp);
            $type = (string) ($line['lineType'] ?? '');

            if (\in_array($type, ['', 'product'], true) && 'voucher' !== ($line['kind'] ?? '') && (int) ($line['taxClass'] ?? 0) > 0) {
                $pot = self::pot((int) $line['taxClass'], $country, $rate);
                $caps[$pot] = ($caps[$pot] ?? BigDecimal::zero())->plus($credit);
            } elseif ('discount' === $type) {
                $discounts[$country.'|'.$rate] = ($discounts[$country.'|'.$rate] ?? BigDecimal::zero())->plus($credit);
            }
        }

        // A discount at a country and rate is shared among the pots there by
        // what they credit — taken whole from each, it would count twice.
        $credited = [];

        foreach ($caps as $pot => $cap) {
            [, $country, $rate] = explode('|', $pot);
            $credited[$country.'|'.$rate] = ($credited[$country.'|'.$rate] ?? BigDecimal::zero())->plus($cap);
        }

        foreach ($caps as $pot => $cap) {
            [, $country, $rate] = explode('|', $pot);
            $discount = $discounts[$country.'|'.$rate] ?? null;

            if (null !== $discount && $credited[$country.'|'.$rate]->isPositive()) {
                $caps[$pot] = $cap->plus($discount->multipliedBy($cap)->dividedBy($credited[$country.'|'.$rate], 4, RoundingMode::HalfUp));
            }
        }

        return $caps;
    }

    private static function pot(int $class, string $country, string $rate): string
    {
        return $class.'|'.strtoupper(trim($country)).'|'.BigDecimal::of('' !== $rate ? $rate : '0')->toScale(4, RoundingMode::HalfUp);
    }

    private function digits(int $orderId): int
    {
        $currency = (string) $this->connection->fetchOne('SELECT currency FROM tl_consho_order WHERE id = ?', [$orderId]);

        return '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
    }

    /**
     * A cancelled order (6.6, D2): what its vouchers paid goes back onto them,
     * and the vouchers it bought are blocked — every one, used in part or
     * not: the money for them goes back, so what is left on them must not
     * stay spendable beside it.
     */
    public function afterCancellation(int $orderId, int $now): void
    {
        /*
         * Under the order's lock (WG-7), like a withdrawal's
         * refund: a cancellation and a refund at the same moment would
         * otherwise both read what the vouchers paid and both book it back.
         */
        $this->connection->fetchOne('SELECT id FROM tl_consho_order WHERE id = ? FOR UPDATE', [$orderId]);

        $total = $this->paidTotal($orderId);

        if ($total->isPositive()) {
            $language = (string) $this->connection->fetchOne('SELECT language FROM tl_consho_order WHERE id = ?', [$orderId]);
            $this->refund($orderId, (string) $total, $now, '', $language);
        }

        $this->connection->executeStatement(
            "UPDATE tl_consho_voucher SET blocked = 1, tstamp = ? WHERE orderId = ? AND source = 'purchase' AND blocked = 0",
            [$now, $orderId],
        );
    }

    /**
     * A withdrawal of bought vouchers (WG7, WG-7): as long as a voucher
     * is not redeemed it can be withdrawn; once redeemed, it bought something
     * that has to be given back first — per withdrawn piece one voucher of the
     * line is taken back — blocked, marked with the withdrawal — and only
     * one NOT REDEEMED, its balance its whole value. What was bought with a
     * redeemed one has to be withdrawn or cancelled first; its refund fills
     * the voucher again (WG11), and then it can go.
     *
     * Pieces without a voucher need none: the line is unpaid and nothing was
     * issued (a line's vouchers are issued together, at the payment), or
     * another withdrawal took it — and the release never issues a withdrawn
     * piece. So only a redeemed voucher can stand in the way.
     *
     * Three things stand in the way, said apart (WG-7): REDEEMED — an order it paid has not had it back; BLOCKED by the
     * operator (lost, replaced): taking it back would pay out what may already
     * have been handed over again; CHANGED by hand — its balance is not its
     * value although nothing was redeemed. Each the operator decides.
     *
     * The vouchers are read under lock (L-A), in id order like the purchase
     * click: a purchase paying with one at this very moment either finished
     * before — and the voucher reads redeemed — or waits.
     *
     * @param array<int, string> $accepted order item id => accepted quantity, voucher lines only
     *
     * @return list<array{code: string, reason: 'redeemed'|'blocked'|'changed', orders: string}> what is in the way, nothing blocked; [] when done
     */
    public function takeBack(int $orderId, int $withdrawalId, array $accepted, int $now): array
    {
        $plan = [];
        $inTheWay = [];

        foreach ($accepted as $itemId => $quantity) {
            $pieces = BigDecimal::of($quantity)->toScale(0, RoundingMode::Down)->toInt();

            if ($pieces < 1) {
                continue;
            }

            $free = [];

            foreach ($this->connection->fetchAllAssociative(
                "SELECT id, code, faceValue, balance, blocked FROM tl_consho_voucher
                  WHERE orderId = ? AND orderItem = ? AND source = 'purchase' AND withdrawal = 0 ORDER BY id FOR UPDATE",
                [$orderId, $itemId],
            ) as $v) {
                $orders = $this->openRedemptions((int) $v['id']);
                $reason = match (true) {
                    '' !== $orders => 'redeemed',
                    (bool) $v['blocked'] => 'blocked',
                    !BigDecimal::of((string) $v['balance'])->isEqualTo((string) $v['faceValue']) => 'changed',
                    default => null,
                };

                if (null === $reason) {
                    $free[] = (int) $v['id'];
                } else {
                    $inTheWay[(int) $itemId][] = ['code' => VoucherCode::masked((string) $v['code']), 'reason' => $reason, 'orders' => $orders];
                }
            }

            // Short only where something stands in the way; without, the
            // missing pieces have no voucher (the docblock above).
            if (\count($free) >= $pieces || !isset($inTheWay[(int) $itemId])) {
                array_push($plan, ...\array_slice($free, 0, $pieces));
                unset($inTheWay[(int) $itemId]);
            }
        }

        if ([] !== $inTheWay) {
            return array_merge(...array_values($inTheWay));
        }

        foreach ($plan as $id) {
            $this->connection->update('tl_consho_voucher', ['blocked' => 1, 'withdrawal' => $withdrawalId, 'tstamp' => $now], ['id' => $id]);
        }

        return [];
    }

    /**
     * The orders a voucher paid that have not had it back, by number — what
     * the operator has to withdraw or cancel first. '' for none: a
     * redemption booked back in full no longer stands in the way.
     */
    private function openRedemptions(int $voucherId): string
    {
        return implode(', ', array_map(strval(...), $this->connection->fetchFirstColumn(
            "SELECT o.orderNumber FROM tl_consho_voucher_booking b
               JOIN tl_consho_order o ON o.id = b.orderId
              WHERE b.pid = ? AND b.type IN ('redeem', 'refund')
              GROUP BY o.id, o.orderNumber HAVING SUM(b.amount) < 0 ORDER BY o.orderNumber",
            [$voucherId],
        )));
    }

    private function label(string $code, string $language): string
    {
        $masked = VoucherCode::masked($code);

        return $this->translator?->trans('CONSHO.cart.voucher.applied', [$masked], 'contao_default', '' !== $language ? $language : null)
            ?? 'Gift voucher '.$masked;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;

/**
 * The vouchers pay, at the purchase click (block Wertgutscheine, 6.5, E4,
 * E5).
 *
 * Inside OrderPlacer's transaction: each voucher is locked, asked again
 * whether it can pay, and booked — a `redeem` booking and a payment on the
 * order, the two pointing at each other. A voucher that can no longer pay
 * what the review page showed stops the click; the transaction rolls back
 * with every booking before it, and nothing is ordered.
 *
 * The payment names the voucher by its last group only (E5): the invoice
 * may reach someone other than the one the voucher was given to.
 */
class VoucherRedemption
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VoucherLedger $ledger,
        private readonly VoucherWallet $wallet,
        private readonly TranslatorInterface|null $translator = null,
    ) {
    }

    /**
     * @throws VoucherChangedAtPurchase
     */
    public function redeem(int $orderId, OrderDraft $draft, int $now): void
    {
        /*
         * All of them locked first, in id order (WG-7): two baskets
         * paying with A and B in opposite order would otherwise each hold one
         * and wait for the other — a deadlock instead of the second click
         * simply waiting and finding the balance gone.
         */
        $ids = array_values(array_unique(array_map(static fn (array $voucher): int => $voucher['id'], $draft->vouchers)));
        sort($ids);

        if ([] !== $ids) {
            $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_voucher WHERE id IN (?) ORDER BY id FOR UPDATE', [$ids], [ArrayParameterType::INTEGER]);
        }

        foreach ($draft->vouchers as $voucher) {
            $amount = BigDecimal::of($voucher['amount']);

            if (!$amount->isPositive()) {
                continue;
            }

            $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_voucher WHERE id = ? FOR UPDATE', [$voucher['id']]);

            if (false === $row || VoucherStatus::Usable !== $this->wallet->statusOf($row, $draft->shopId, $draft->currency, $now)) {
                throw VoucherChangedAtPurchase::for($voucher['id']);
            }

            // EZ-4: what a single-purpose voucher pays was taxed at its sale.
            $singlePurpose = SinglePurpose::of($row);

            $this->connection->insert('tl_consho_payment', [
                'pid' => $orderId,
                'tstamp' => $now,
                'receivedAt' => $now,
                'amount' => (string) $amount->toScale(4),
                'method' => mb_substr($this->label((string) $row['code'], $draft->language), 0, 64),
                'reference' => 'voucher:'.$voucher['id'],
                'prepaidTaxRate' => $singlePurpose->taxRate ?? '0.0000',
                'prepaidTaxCountry' => $singlePurpose->taxCountry ?? '',
                'prepaidTax' => null === $singlePurpose ? '0.0000' : $singlePurpose->taxIn((string) $amount, Currencies::getFractionDigits($draft->currency)),
            ]);

            $paymentId = (int) $this->connection->lastInsertId();

            try {
                $this->ledger->book((int) $voucher['id'], (string) $amount->negated(), VoucherBookingType::Redeem, $now, $orderId, $paymentId);
            } catch (VoucherBalanceTooLow) {
                throw VoucherChangedAtPurchase::for($voucher['id']);
            }
        }
    }

    private function label(string $code, string $language): string
    {
        $masked = VoucherCode::masked($code);

        return $this->translator?->trans('CONSHO.cart.voucher.applied', [$masked], 'contao_default', '' !== $language ? $language : null)
            ?? 'Gift voucher '.$masked;
    }
}

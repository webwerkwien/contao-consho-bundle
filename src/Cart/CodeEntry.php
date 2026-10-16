<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;

/**
 * A code typed in the basket, past the brake (block Wertgutscheine, WG5).
 *
 * Taking a code away is never braked. Once the brake holds, the code is not
 * even looked at — otherwise the eleventh guess would still be answered, only
 * with a notice beside it. An unknown discount code is kept like any other
 * (H-4): the basket says it is unknown, and that answer is what counts as a
 * failure.
 *
 * A voucher code (WG8, E3) is kept only when it is one: a wrong one is
 * answered with a notice and counted, so the basket never carries a guess.
 * One brake for both fields — two would double the guesses.
 */
final class CodeEntry
{
    /**
     * Vouchers per basket. Enough for any purchase anyone makes with gift
     * vouchers, and what the column holds with room to spare.
     */
    public const MAX_VOUCHERS = 10;

    public function __construct(
        private readonly DbalCartRepository $repository,
        private readonly CartDiscounts $discounts,
        private readonly CodeAttemptLimit $attempts,
        private readonly VoucherWallet $vouchers,
    ) {
    }

    /**
     * @param string $code '' takes the code away
     */
    public function submit(int $cartId, string $code, string $ip, int $now): void
    {
        $code = trim($code);

        if ('' !== $code && $this->attempts->blocked($ip, $cartId)) {
            $this->repository->setNotice($cartId, CartNotice::TooManyCodes);

            return;
        }

        if ('' !== $code && !$this->discounts->knows($code)) {
            $this->attempts->failed($ip, $cartId);
        }

        $this->repository->setDiscountCode($cartId, $code, $now);
    }

    /**
     * A voucher typed in the basket. Kept whatever it is worth right now —
     * the basket says what it pays — but only if this shop knows it.
     */
    public function addVoucher(int $cartId, int $shopId, string $typed, string $ip, int $now): void
    {
        if ('' === trim($typed)) {
            return;
        }

        if ($this->attempts->blocked($ip, $cartId)) {
            $this->repository->setNotice($cartId, CartNotice::TooManyCodes);

            return;
        }

        $voucher = $this->vouchers->findForShop($typed, $shopId);

        if (null === $voucher) {
            $this->attempts->failed($ip, $cartId);
            $this->repository->setNotice($cartId, CartNotice::VoucherUnknown);

            return;
        }

        $codes = $this->repository->vouchersOf($cartId);

        if (\in_array($voucher['code'], $codes, true)) {
            return;
        }

        if (\count($codes) >= self::MAX_VOUCHERS) {
            $this->repository->setNotice($cartId, CartNotice::TooManyVouchers);

            return;
        }

        $this->repository->setVouchers($cartId, [...$codes, (string) $voucher['code']], $now);
    }

    public function removeVoucher(int $cartId, string $typed, int $now): void
    {
        $code = VoucherCode::normalise($typed);
        $codes = $this->repository->vouchersOf($cartId);

        if ('' !== $code && \in_array($code, $codes, true)) {
            $this->repository->setVouchers($cartId, array_values(array_diff($codes, [$code])), $now);
        }
    }
}

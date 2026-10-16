<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;

/**
 * The vouchers of a basket and what they pay (block Wertgutscheine, 6.5,
 * WG8, WG9).
 *
 * Read only — the balance changes at the purchase click, under a lock, in
 * VoucherLedger. Here a voucher is asked whether it can pay at all (status)
 * and how much of an amount it covers: in the order the codes were typed,
 * each up to its balance, until nothing is left to cover.
 *
 * Valid in the shop that issued it and in the shops the operator unlocked
 * (WG9). A voucher of another shop reads `Unknown` — whether it exists
 * elsewhere is not this shop's to tell.
 */
class VoucherWallet
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The voucher behind a typed code, if it is one this shop takes at all —
     * whatever it is worth right now. For the basket's field: a code this
     * answers null for is a wrong guess (WG5).
     *
     * @return array<string, mixed>|null
     */
    public function findForShop(string $typed, int $shopId): array|null
    {
        $code = VoucherCode::normalise($typed);

        if ('' === $code) {
            return null;
        }

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_voucher WHERE code = ?', [$code]);

        return false !== $row && $row['code'] === $code && self::validIn($row, $shopId) ? $row : null;
    }

    /**
     * The vouchers behind the stored codes, in their order, each with what it
     * pays of $coverable. $coverable '0' (the basket, where the shipping is
     * not known yet) shows the balances and applies nothing.
     *
     * @param list<string> $codes stored codes, already normalised
     *
     * @return list<AppliedVoucher>
     */
    public function apply(array $codes, int $shopId, string $currency, string $coverable, int $digits, int $now): array
    {
        if ([] === $codes) {
            return [];
        }

        $rows = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_voucher WHERE code IN (?)', [$codes], [ArrayParameterType::STRING]) as $row) {
            $rows[(string) $row['code']] = $row;
        }

        $left = BigDecimal::of($coverable)->toScale($digits, RoundingMode::Down);
        $applied = [];

        foreach ($codes as $code) {
            $row = $rows[$code] ?? null;

            if (null === $row) {
                $applied[] = new AppliedVoucher(0, $code, VoucherStatus::Unknown, '0.0000', '0', '0.0000');

                continue;
            }

            $balance = BigDecimal::of((string) $row['balance'])->toScale(4);
            $status = $this->statusOf($row, $shopId, $currency, $now);
            $takes = BigDecimal::zero()->toScale($digits);

            if (VoucherStatus::Usable === $status && $left->isPositive()) {
                // Never more than the balance holds, on the currency's places.
                $takes = BigDecimal::min($left, $balance->toScale($digits, RoundingMode::Down));
                $left = $left->minus($takes);
            }

            $applied[] = new AppliedVoucher(
                (int) $row['id'],
                $code,
                $status,
                (string) $balance,
                (string) $takes,
                (string) $balance->minus($takes)->toScale(4),
            );
        }

        return $applied;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function statusOf(array $row, int $shopId, string $currency, int $now): VoucherStatus
    {
        return match (true) {
            !self::validIn($row, $shopId) => VoucherStatus::Unknown,
            (bool) $row['blocked'] => VoucherStatus::Blocked,
            self::expired($row['validUntil'] ?? null, $now) => VoucherStatus::Expired,
            strtoupper((string) $row['currency']) !== strtoupper($currency) => VoucherStatus::OtherCurrency,
            !BigDecimal::of((string) $row['balance'])->isPositive() => VoucherStatus::Spent,
            default => VoucherStatus::Usable,
        };
    }

    /**
     * Past its last day (WG10): `validUntil` is that day's midnight, the day
     * itself still counts. Null or empty: unlimited.
     */
    public static function expired(mixed $validUntil, int $now): bool
    {
        if (null === $validUntil || '' === (string) $validUntil) {
            return false;
        }

        $end = (new \DateTimeImmutable('@'.(int) $validUntil))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0)
            ->modify('+1 day');

        return $now >= $end->getTimestamp();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function validIn(array $row, int $shopId): bool
    {
        return (int) $row['shop'] === $shopId || \in_array($shopId, MethodSettings::selectedIds($row['shops'] ?? null), true);
    }
}

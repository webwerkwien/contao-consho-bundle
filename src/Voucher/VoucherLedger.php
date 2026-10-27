<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The one place a voucher's balance changes (block Wertgutscheine, WG9).
 *
 * Every change is a row in tl_consho_voucher_booking, written in the same
 * transaction as the balance, under a lock on the voucher row: two purchases
 * spending the same voucher at the same moment wait for each other, and the
 * second one sees what the first left. The balance is therefore always the
 * sum of the bookings — a figure nobody can explain is what the bookings
 * exist to prevent (the reasoning of StockBooking, G2).
 *
 * ⚠️ The second buyer has to SEE the first one's booking after waiting for
 * the lock. OrderPlacer runs the purchase under READ COMMITTED for exactly
 * this (H, P7; the last key of block G).
 */
final class VoucherLedger
{
    private const ATTEMPTS = 5;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * A new voucher with its value as the first booking.
     *
     * @param string $paid       what was paid for it — the tax base once it is
     *                           spent (Art. 73a MwStSystRL); '0' for one issued
     *                           by hand
     * @param int|null $validUntil the last day, as Contao stores a date;
     *                             null: unlimited (WG10)
     *
     * @return int the voucher's id
     */
    public function issue(
        int $shopId,
        string $currency,
        string $value,
        string $paid,
        int|null $validUntil,
        string $source,
        int $now,
        int $orderId = 0,
        int $orderItem = 0,
        int $user = 0,
        string $note = '',
        SinglePurpose|null $singlePurpose = null,
    ): int {
        if (!BigDecimal::of($value)->isPositive()) {
            throw new \InvalidArgumentException(\sprintf('A voucher needs a value above zero, %s given.', $value));
        }

        return $this->connection->transactional(function () use ($shopId, $currency, $value, $paid, $validUntil, $source, $now, $orderId, $orderItem, $user, $note, $singlePurpose): int {
            $id = $this->insertWithFreshCode([
                'tstamp' => $now,
                'createdAt' => $now,
                'shop' => $shopId,
                'currency' => strtoupper($currency),
                'faceValue' => self::scaled($value),
                'paidAmount' => self::scaled($paid),
                'balance' => '0.0000',
                'validUntil' => $validUntil,
                'source' => $source,
                'orderId' => $orderId,
                'orderItem' => $orderItem,
                'note' => $note,
                // EZ-5: frozen here, never changed after.
                'taxClass' => $singlePurpose->taxClass ?? 0,
                'taxRate' => $singlePurpose->taxRate ?? '0.0000',
                'taxCountry' => $singlePurpose->taxCountry ?? '',
            ]);

            $this->book($id, $value, VoucherBookingType::Issue, $now, $orderId, 0, $user, $note);

            return $id;
        });
    }

    /**
     * Changes the balance by $amount (negative to spend) and records why.
     *
     * @throws VoucherBalanceTooLow
     *
     * @return string the balance after the booking
     */
    public function book(
        int $voucherId,
        string $amount,
        VoucherBookingType $type,
        int $now,
        int $orderId = 0,
        int $paymentId = 0,
        int $user = 0,
        string $note = '',
    ): string {
        return $this->connection->transactional(function () use ($voucherId, $amount, $type, $now, $orderId, $paymentId, $user, $note): string {
            $balance = $this->connection->fetchOne('SELECT balance FROM tl_consho_voucher WHERE id = ? FOR UPDATE', [$voucherId]);

            if (false === $balance) {
                throw new \InvalidArgumentException(\sprintf('Voucher %d does not exist.', $voucherId));
            }

            $change = self::scaled($amount);
            $after = BigDecimal::of((string) $balance)->plus($change);

            if ($after->isNegative()) {
                throw VoucherBalanceTooLow::for($voucherId, (string) $balance, $change);
            }

            $after = (string) $after->toScale(4);

            $this->connection->update('tl_consho_voucher', ['balance' => $after, 'tstamp' => $now], ['id' => $voucherId]);
            $this->connection->insert('tl_consho_voucher_booking', [
                'pid' => $voucherId,
                'tstamp' => $now,
                'createdAt' => $now,
                'type' => $type->value,
                'amount' => $change,
                'balanceAfter' => $after,
                'orderId' => $orderId,
                'paymentId' => $paymentId,
                'user' => $user,
                'note' => $note,
            ]);

            return $after;
        });
    }

    /**
     * The voucher behind a typed code, or null. Compared in PHP as well: the
     * column's collation would match regardless of case (W6) — harmless for a
     * code of capitals only, but the stored shape is the one that counts.
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $typed): array|null
    {
        $code = VoucherCode::normalise($typed);

        if ('' === $code) {
            return null;
        }

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_voucher WHERE code = ?', [$code]);

        return false !== $row && $row['code'] === $code ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertWithFreshCode(array $row): int
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                $this->connection->insert('tl_consho_voucher', ['code' => VoucherCode::generate(), ...$row]);

                return (int) $this->connection->lastInsertId();
            } catch (UniqueConstraintViolationException $e) {
                // A collision among 7 × 10²³ codes — draw again, a few times.
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private static function scaled(string $amount): string
    {
        return (string) BigDecimal::of('' === $amount ? '0' : $amount)->toScale(4, RoundingMode::HalfUp);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;

/**
 * "Schlüssel aus dem Vorrat" (F-6, F19): one code per piece, taken from the
 * codes the operator pasted into the article — game keys, a third party's
 * voucher codes.
 *
 * All or nothing: an entitlement for three pieces gets three codes or none,
 * and then the line waits for the pool to be refilled (the release asks
 * again every hour, the back end names the article). Handing out two of
 * three would be a delivery the order does not show.
 *
 * Taken one by one with `… WHERE entitlement = 0`: two releases of different
 * orders may run at the same time, and they lock their ORDER, not the pool.
 * An update that finds its code already taken simply moves on to the next.
 * A pool per article — a variant sells its own codes, not its main product's.
 */
final class KeyPoolHandler implements DeliveryHandlerInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public static function getType(): string
    {
        return 'key';
    }

    public function release(Entitlement $entitlement): bool
    {
        $needed = BigDecimal::of($entitlement->quantity)->toScale(0, RoundingMode::Down)->toInt();

        if ($needed < 1) {
            return true;
        }

        $taken = 0;
        $candidates = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_key WHERE pid = ? AND entitlement = 0 ORDER BY id',
            [$entitlement->productId],
        );

        foreach ($candidates as $id) {
            $taken += $this->connection->executeStatement(
                'UPDATE tl_consho_key SET entitlement = ?, assignedAt = ?, tstamp = ? WHERE id = ? AND entitlement = 0',
                [$entitlement->id, $entitlement->validFrom, $entitlement->validFrom, (int) $id],
            );

            if ($taken >= $needed) {
                return true;
            }
        }

        // Not enough — the release rolls back what was taken (savepoint).
        return false;
    }

    /**
     * The codes of an entitlement, for the order page.
     *
     * @return list<string>
     */
    public function codesOf(int $entitlementId): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT code FROM tl_consho_key WHERE entitlement = ? ORDER BY id', [$entitlementId]));
    }
}

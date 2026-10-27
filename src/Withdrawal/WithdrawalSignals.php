<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Brick\Math\BigDecimal;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Order\StatusColour;

/**
 * The three symbols of a withdrawal (D-3, F12) — the order list's three
 * turned round: return shipment instead of shipment, refund instead
 * of payment, and the whole.
 *
 *  - state (undo):  received/accepted orange, OVERDUE red, rejected grey,
 *                   refunded green
 *  - return (copy): nothing back grey, part orange, all green; grey "after
 *                   the decision" while undecided, grey "not required"
 *                   where nothing was accepted
 *  - refund (sync): open grey, booked green
 *
 * One place for the list and the page head, so the two cannot disagree. The
 * title says the state in words: colour alone is unreadable for anyone
 * red-green blind (see OrderLabelListener::signal()).
 */
class WithdrawalSignals
{
    /** § 357 Abs. 1 BGB, § 14 Abs. 1 FAGG. */
    public const REFUND_DAYS = 14;

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function refundDue(int $receivedAt): int
    {
        return strtotime(\sprintf('+%d days', self::REFUND_DAYS), $receivedAt) ?: $receivedAt;
    }

    /**
     * @param array<string, mixed> $withdrawal a row of tl_consho_withdrawal
     *
     * @return array{state: array{0: StatusColour, 1: string}, return: array{0: StatusColour, 1: string}, refund: array{0: StatusColour, 1: string}}
     */
    public function of(array $withdrawal, int $now): array
    {
        $state = (string) ($withdrawal['state'] ?? 'received');
        $refunded = null !== ($withdrawal['refundedAt'] ?? null);
        $overdue = !$refunded && 'rejected' !== $state && self::refundDue((int) ($withdrawal['receivedAt'] ?? 0)) < $now;

        $overall = match (true) {
            'rejected' === $state => [StatusColour::Open, 'rejected'],
            $refunded => [StatusColour::Done, 'done'],
            $overdue => [StatusColour::Attention, 'overdue'],
            default => [StatusColour::Progress, 'accepted' === $state ? 'accepted' : 'received'],
        };

        return [
            'state' => $overall,
            'return' => $this->returned($withdrawal),
            'refund' => match (true) {
                $refunded => [StatusColour::Done, 'done'],
                'rejected' === $state => [StatusColour::Open, 'na'],
                default => [StatusColour::Open, 'open'],
            },
        ];
    }

    /**
     * @param array{state: array{0: StatusColour, 1: string}, return: array{0: StatusColour, 1: string}, refund: array{0: StatusColour, 1: string}} $signals
     */
    public static function html(array $signals): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_withdrawal']['signals'] ?? [];
        $html = '';

        foreach (['state' => 'undo', 'return' => 'copy', 'refund' => 'sync'] as $dimension => $icon) {
            [$colour, $key] = $signals[$dimension];

            $html .= \sprintf(
                '<span class="consho-signal consho-signal--%s consho-signal--%s" title="%s"></span>',
                $icon,
                $colour->value,
                StringUtil::specialchars((string) ($lang[$dimension][$key] ?? $dimension.'.'.$key)),
            );
        }

        return '<span class="consho-signals">'.$html.'</span>';
    }

    /**
     * @param array<string, mixed> $withdrawal
     *
     * @return array{0: StatusColour, 1: string}
     */
    private function returned(array $withdrawal): array
    {
        $sums = $this->connection->fetchAssociative(
            'SELECT SUM(acceptedQuantity) AS accepted, SUM(returnedQuantity) AS returned FROM tl_consho_withdrawal_item WHERE pid = ?',
            [(int) ($withdrawal['id'] ?? 0)],
        );

        $accepted = BigDecimal::of((string) (($sums['accepted'] ?? null) ?: '0'));
        $returned = BigDecimal::of((string) (($sums['returned'] ?? null) ?: '0'));

        // Undecided, nothing is accepted yet — "not required" would say more
        // than is known (D-3).
        if ('received' === ($withdrawal['state'] ?? 'received')) {
            return [StatusColour::Open, 'pending'];
        }

        if ('accepted' !== ($withdrawal['state'] ?? '') || !$accepted->isPositive()) {
            return [StatusColour::Open, 'na'];
        }

        return match (true) {
            $returned->isGreaterThanOrEqualTo($accepted) => [StatusColour::Done, 'complete'],
            $returned->isPositive() => [StatusColour::Progress, 'partial'],
            default => [StatusColour::Open, 'none'],
        };
    }
}

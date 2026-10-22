<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;

/**
 * Records a withdrawal and sends the confirmation of receipt (D-2, spec 6.1
 * step 4).
 *
 * ── The record first, the mail after ──────────────────────────────────────
 *
 * The declaration is effective when it ARRIVES (§ 356a Abs. 1 BGB: "fristgerecht,
 * wenn … vor Ablauf der Frist abgegeben"). So the row is committed before
 * anything is sent, and a mail server that is down changes nothing about it:
 * `confirmationSentAt` stays NULL and the back end shows the gap.
 *
 * ── Nothing that can be missing may stop it ─────────────────────────────
 *
 * No number range (decision E3): the number is the order number and a count.
 * No order page: the confirmation goes out without the button. What can
 * refuse a withdrawal is only an order without a withdrawal function (period
 * 0, cancelled before shipping, a subscription's follow-up, released digital
 * content) or a tampered form.
 *
 * ── Accepted, then judged (WA, 2026-10-22) ────────────────────────────────
 *
 * The window ends at "last shipment + period + grace" — an estimate, since
 * the period runs from RECEIPT. A declaration after that end is stored and
 * confirmed with `afterWindow` set (B1); the operator decides. A declaration
 * from the form that matches no order is stored as well, unassigned (A1,
 * recordUnassigned()), and confirmed to the address the consumer gave
 * (§ 356a Abs. 2 Nr. 3 BGB).
 *
 * ── The number under concurrency ─────────────────────────────────────────
 *
 * Since K2 the order row is locked while the remainder is checked and the
 * withdrawal written, so a second declaration waits and counts the first.
 * The unique key (order, number) stays as the guard where no lock is taken
 * (SQLite in the tests): a duplicate "-Wn+1" is refused and the number after
 * is tried — nested in the outer transaction, as a savepoint.
 */
final class WithdrawalRecorder
{
    private const ATTEMPTS = 3;

    /**
     * Free text from an unassigned declaration is cut to this many characters
     * before it is stored and mailed (A3): the confirmation goes to an
     * address anyone can type, and must not carry a letter of their own.
     */
    public const UNASSIGNED_TEXT_LIMIT = 2000;

    public function __construct(
        private readonly Connection $connection,
        private readonly WithdrawalWindowReader $windows,
        private readonly DbalWithdrawalRepository $withdrawals,
        private readonly OrderEventMail $mail,
        private readonly OrderPageLink $links,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // A3: confirmations of unassigned declarations, per shop and per address.
        #[Autowire(service: 'consho.rate_limit.withdrawal_unassigned_shop')]
        private readonly RateLimiterFactoryInterface|null $perShop = null,
        #[Autowire(service: 'consho.rate_limit.withdrawal_unassigned_address')]
        private readonly RateLimiterFactoryInterface|null $perAddress = null,
    ) {
    }

    public function record(WithdrawalDeclaration $declaration, int $now): WithdrawalResult
    {
        $order = $this->connection->fetchAssociative(
            'SELECT id, shop, orderNumber, email, language FROM tl_consho_order WHERE id = ?',
            [$declaration->orderId],
        );

        if (false === $order) {
            return new WithdrawalResult(WithdrawalOutcome::UnknownOrder);
        }

        $window = $this->windows->of($declaration->orderId);
        $late = null !== $window && $window->isLateAt($now);

        if (!$late && !$window?->isOpenAt($now)) {
            return new WithdrawalResult(WithdrawalOutcome::Closed);
        }

        /*
         * What is left is read under the order's lock (Nachlese K2, spec D):
         * two declarations of the same remainder in the same instant both
         * passed the check before and together withdrew more than was bought.
         * Locked, the second one reads what the first one took — the same lock
         * a refund and a cancellation take (VoucherReturn).
         */
        $this->connection->beginTransaction();

        try {
            $this->connection->fetchOne('SELECT id FROM tl_consho_order WHERE id = ? FOR UPDATE', [$declaration->orderId]);

            $goods = $this->withdrawals->goods($declaration->orderId);
            $items = self::validItems($declaration->items, $goods);

            if (null === $items) {
                $this->connection->rollBack();

                return new WithdrawalResult(WithdrawalOutcome::InvalidItems);
            }

            [$id, $number] = $this->insert($declaration, $order, $items, $now, $late);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        $viewUrl = $this->links->forMail($order);

        $confirmed = $this->mail->withdrawalReceived($declaration->orderId, [
            'number' => $number,
            'receivedAt' => $now,
            'source' => $declaration->source->value,
            'name' => $declaration->name,
            'email' => (string) $order['email'],
            'scope' => $declaration->scope(),
            'items' => array_map(
                static fn (int $itemId, string $quantity): array => ['quantity' => $quantity, 'name' => $goods[$itemId]['name']],
                array_keys($items),
                $items,
            ),
            'part' => trim($declaration->part),
            'message' => trim($declaration->message),
        ], $viewUrl ?? '');

        if ($confirmed) {
            $this->connection->update('tl_consho_withdrawal', ['confirmationSentAt' => time()], ['id' => $id]);
        } else {
            $this->logger->error(\sprintf('Withdrawal %s was recorded, but its confirmation of receipt did not go out. It has to be confirmed by hand.', $number));
        }

        return new WithdrawalResult(WithdrawalOutcome::Recorded, $id, $number, $now, $confirmed, $late);
    }

    /**
     * A declaration from the form that matched no order (A1). Stored with
     * pid 0 and the shop it came in through, numbered "W-00042" (A2), and
     * confirmed to the address the consumer typed (A3) — unless the limits
     * per shop or per address are used up: then it stands unconfirmed and
     * the operator confirms it by hand, as after a failed mail.
     */
    public function recordUnassigned(int $shopId, string $language, string $orderReference, string $email, string $name, string $part, string $message, int $now): WithdrawalResult
    {
        $orderReference = mb_substr(trim($orderReference), 0, 80);
        $part = self::cut($part);
        $message = self::cut($message);

        $this->connection->beginTransaction();

        try {
            $this->connection->insert('tl_consho_withdrawal', [
                'pid' => 0,
                'shop' => $shopId,
                'tstamp' => $now,
                // Unique for the moment it takes to learn the ID.
                'number' => 'W-'.bin2hex(random_bytes(8)),
                'receivedAt' => $now,
                'source' => WithdrawalSource::Form->value,
                'name' => trim($name),
                'orderReference' => $orderReference,
                'email' => trim($email),
                'scope' => '' !== $part ? 'part' : 'all',
                'part' => '' !== $part ? $part : null,
                'message' => '' !== $message ? $message : null,
                'state' => 'received',
            ]);

            $id = (int) $this->connection->lastInsertId();
            $number = \sprintf('W-%05d', $id);
            $this->connection->update('tl_consho_withdrawal', ['number' => $number], ['id' => $id]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        $confirmed = false;

        // The address first: a flood on one address must not use up the
        // shop's allowance as well (review WA, K2).
        $limited = !$this->allowed($this->perAddress, 'address-'.mb_strtolower(trim($email))) || !$this->allowed($this->perShop, 'shop-'.$shopId);

        if (!$limited) {
            $confirmed = $this->mail->withdrawalReceivedUnassigned($shopId, trim($email), $language, [
                'number' => $number,
                'receivedAt' => $now,
                'name' => trim($name),
                'orderReference' => $orderReference,
                'email' => trim($email),
                'scope' => '' !== $part ? 'part' : 'all',
                'part' => $part,
                'message' => $message,
            ]);
        }

        if ($confirmed) {
            $this->connection->update('tl_consho_withdrawal', ['confirmationSentAt' => time()], ['id' => $id]);
        } elseif ($limited) {
            // Wanted, not a failure: in a wave of them twenty errors an hour
            // would bury the real ones (review WA, K3).
            $this->logger->warning(\sprintf('Withdrawal %s (not assigned to an order) was recorded; its confirmation of receipt was held back by the mail limit per shop or per address. Confirm it by hand if it is genuine.', $number));
        } else {
            $this->logger->error(\sprintf('Withdrawal %s (not assigned to an order) was recorded, but its confirmation of receipt did not go out. It has to be confirmed by hand.', $number));
        }

        return new WithdrawalResult(WithdrawalOutcome::Recorded, $id, $number, $now, $confirmed);
    }

    private function allowed(RateLimiterFactoryInterface|null $factory, string $key): bool
    {
        return null === $factory || $factory->create($key)->consume()->isAccepted();
    }

    private static function cut(string $text): string
    {
        return mb_substr(trim($text), 0, self::UNASSIGNED_TEXT_LIMIT);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, string>   $items
     *
     * @return array{0: int, 1: string}
     */
    private function insert(WithdrawalDeclaration $declaration, array $order, array $items, int $now, bool $late): array
    {
        for ($attempt = 1; ; ++$attempt) {
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal WHERE pid = ?', [$declaration->orderId]);
            $number = \sprintf('%s-W%d', $order['orderNumber'], $count + $attempt);

            $this->connection->beginTransaction();

            try {
                $this->connection->insert('tl_consho_withdrawal', [
                    'pid' => $declaration->orderId,
                    'shop' => (int) $order['shop'],
                    'tstamp' => $now,
                    'number' => $number,
                    'receivedAt' => $now,
                    'afterWindow' => $late ? 1 : 0,
                    'source' => $declaration->source->value,
                    'name' => trim($declaration->name),
                    'email' => (string) $order['email'],
                    'scope' => $declaration->scope(),
                    'orderReference' => (string) $order['orderNumber'],
                    'part' => 'part' === $declaration->scope() ? trim($declaration->part) : null,
                    'message' => '' !== trim($declaration->message) ? trim($declaration->message) : null,
                    'state' => 'received',
                ]);

                $id = (int) $this->connection->lastInsertId();

                foreach ($items as $itemId => $quantity) {
                    $this->connection->insert('tl_consho_withdrawal_item', ['pid' => $id, 'tstamp' => $now, 'orderItem' => $itemId, 'quantity' => $quantity]);
                }

                $this->connection->commit();

                return [$id, $number];
            } catch (UniqueConstraintViolationException $e) {
                $this->connection->rollBack();

                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            } catch (\Throwable $e) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }

                throw $e;
            }
        }
    }

    /**
     * Positions of THIS order's goods, each positive and no more than is left
     * after earlier withdrawals, a subscription line exactly what is left
     * (P77). null where anything does not hold — which a form rendered from
     * the order cannot produce.
     *
     * @param array<int, string>                                                           $items
     * @param array<int, array{name: string, quantity: string, left: string, whole: bool}> $goods
     *
     * @return array<int, string>|null
     */
    private static function validItems(array $items, array $goods): array|null
    {
        if ([] === $items) {
            return [];
        }

        $valid = [];

        foreach ($items as $itemId => $quantity) {
            if (!isset($goods[$itemId])) {
                return null;
            }

            try {
                $amount = BigDecimal::of($quantity);
            } catch (MathException) {
                return null;
            }

            if (!$amount->isPositive() || $amount->isGreaterThan($goods[$itemId]['left'])) {
                return null;
            }

            if ($goods[$itemId]['whole'] && !$amount->isEqualTo($goods[$itemId]['left'])) {
                return null;
            }

            $valid[$itemId] = (string) $amount;
        }

        return $valid;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * refuse a withdrawal is only what the customer can see — the window
 * closed — or a tampered form.
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

    public function __construct(
        private readonly Connection $connection,
        private readonly WithdrawalWindowReader $windows,
        private readonly DbalWithdrawalRepository $withdrawals,
        private readonly OrderEventMail $mail,
        private readonly OrderPageLink $links,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
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

        if (!$this->windows->of($declaration->orderId)?->isOpenAt($now)) {
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

            [$id, $number] = $this->insert($declaration, $order, $items, $now);

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

        return new WithdrawalResult(WithdrawalOutcome::Recorded, $id, $number, $now, $confirmed);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, string>   $items
     *
     * @return array{0: int, 1: string}
     */
    private function insert(WithdrawalDeclaration $declaration, array $order, array $items, int $now): array
    {
        for ($attempt = 1; ; ++$attempt) {
            $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal WHERE pid = ?', [$declaration->orderId]);
            $number = \sprintf('%s-W%d', $order['orderNumber'], $count + $attempt);

            $this->connection->beginTransaction();

            try {
                $this->connection->insert('tl_consho_withdrawal', [
                    'pid' => $declaration->orderId,
                    'tstamp' => $now,
                    'number' => $number,
                    'receivedAt' => $now,
                    'source' => $declaration->source->value,
                    'name' => trim($declaration->name),
                    'email' => (string) $order['email'],
                    'scope' => $declaration->scope(),
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

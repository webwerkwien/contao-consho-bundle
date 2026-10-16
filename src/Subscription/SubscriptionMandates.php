<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Payment\SubscriptionLineView;

/**
 * The mandate of a subscription (Abo-4b, P93): what a provider kept at the
 * first payment to charge the follow-up orders without the customer.
 *
 * "The order's subscriptions" means the same here in both directions: those
 * the order set up (`firstOrder`) and, for a follow-up order, the one it
 * bills — a follow-up paid by the customer in the browser may bring a new
 * mandate as well. An ended subscription takes none.
 */
class SubscriptionMandates
{
    /** The column's length. A longer mandate is refused, never cut — a cut one charges nothing. */
    public const MAX_LENGTH = 255;

    private const OF_ORDER = "(firstOrder = ? OR id = (SELECT subscription FROM tl_consho_order WHERE id = ?)) AND state <> 'ended'";

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * What a provider needs to know at the first payment (P93): one line per
     * subscription of the order, the price of one period from its purchase
     * line (A9).
     *
     * @return list<SubscriptionLineView>
     */
    public function linesOf(int $orderId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.subscriptionNumber, s.billingInterval, s.termLength, s.termUnit, i.productName, i.lineTotal
               FROM tl_consho_subscription s
               LEFT JOIN tl_consho_order_item i ON i.id = s.orderItem
              WHERE (s.firstOrder = ? OR s.id = (SELECT subscription FROM tl_consho_order WHERE id = ?)) AND s.state <> ?
              ORDER BY s.id',
            [$orderId, $orderId, 'ended'],
        );

        return array_map(static fn (array $row): SubscriptionLineView => new SubscriptionLineView(
            (string) $row['subscriptionNumber'],
            (string) ($row['productName'] ?? ''),
            // Four places on every database (Fall 30): SQLite drops the zeros.
            (string) BigDecimal::of((string) ($row['lineTotal'] ?? '0'))->toScale(4, RoundingMode::HalfUp),
            (int) $row['billingInterval'],
            SubscriptionRuleValidator::termMonths((int) $row['termLength'], (string) $row['termUnit']),
        ), $rows);
    }

    /**
     * Keeps the mandate on the order's subscriptions, with the method that
     * charges it. Inside the caller's transaction — the one that records the
     * payment, so both stand or neither.
     *
     * @return int how many subscriptions took it
     */
    public function record(int $orderId, int $methodId, string $mandate, int $now): int
    {
        if ('' === $mandate) {
            return 0;
        }

        if (mb_strlen($mandate) > self::MAX_LENGTH) {
            $this->logger->error(\sprintf('Order %d: the payment method %d returned a mandate of %d characters; at most %d are kept. Follow-up orders go "on account".', $orderId, $methodId, mb_strlen($mandate), self::MAX_LENGTH));

            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE tl_consho_subscription SET mandate = ?, mandateMethod = ?, tstamp = ? WHERE '.self::OF_ORDER,
            [$mandate, $methodId, $now, $orderId, $orderId],
        );
    }
}

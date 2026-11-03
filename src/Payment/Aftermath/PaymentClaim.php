<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Aftermath;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;

/**
 * "Zahlung einfordern" (spec 9, P3): after a chargeback the order owes the
 * money again, and the operator asks the customer for it by a date.
 *
 * The date becomes the order's `paymentDueAt` — what the overdue note, the
 * resting of subscriptions and of digital goods already go by. Nothing of
 * that follows the chargeback itself: only the date the operator sets here.
 *
 * Not final: the back end controller's tests stand a double in for it.
 */
class PaymentClaim
{
    /**
     * The default term. A chargeback comes from a provider, so the order's
     * method has no term of its own to go by.
     */
    public const DEFAULT_DAYS = 14;

    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.general')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * What is open, while a chargeback stands and the order owes money;
     * otherwise null.
     */
    public function outstanding(int $orderId): string|null
    {
        $standing = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_consho_provider_event WHERE pid = ? AND kind = 'chargeback' AND state = 'open' AND reversedAt = 0",
            [$orderId],
        );

        if ($standing < 1) {
            return null;
        }

        $balance = $this->balances->of($orderId);

        if (null === $balance || $balance->cancelled || !BigDecimal::of($balance->outstanding())->isPositive()) {
            return null;
        }

        return $balance->outstanding();
    }

    /**
     * The date offered: today plus the default term, Y-m-d.
     */
    public static function defaultDay(int $now): string
    {
        return date('Y-m-d', $now + self::DEFAULT_DAYS * 86400);
    }

    /**
     * @param string $day the last day to pay by, Y-m-d
     *
     * @return string|null null when claimed; otherwise `notClaimable`,
     *                     `invalidDate`, `notFuture`, or `mailNotSent` — then
     *                     the date is set all the same
     */
    public function claim(int $orderId, string $day, int $now, string $by = ''): string|null
    {
        $outstanding = $this->outstanding($orderId);

        if (null === $outstanding) {
            return 'notClaimable';
        }

        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);

        if (false === $order) {
            return 'notClaimable';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone(date_default_timezone_get()));

        if (false === $date || $date->format('Y-m-d') !== $day) {
            return 'invalidDate';
        }

        // The last second of the day, like PaymentTerm: payable BY that day.
        $dueAt = $date->setTime(23, 59, 59)->getTimestamp();

        if ($dueAt <= $now) {
            return 'notFuture';
        }

        // Replaces the old term, which is long past: the order was paid.
        $this->connection->update('tl_consho_order', ['paymentDueAt' => $dueAt, 'tstamp' => $now], ['id' => $orderId]);

        $this->logger->info(\sprintf(
            'Payment of order %s claimed after a chargeback, due %s%s',
            (string) $order['orderNumber'],
            $day,
            '' !== $by ? ' by '.$by : '',
        ));

        $viewUrl = $this->links->forMail($order);

        if (null === $viewUrl) {
            return 'mailNotSent';
        }

        return $this->mail->paymentClaim($orderId, $outstanding, $dueAt, $viewUrl) ? null : 'mailNotSent';
    }
}

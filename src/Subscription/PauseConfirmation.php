<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;

/**
 * "Pause bestätigt" (Abo pausieren, P110) after a pause was announced — by
 * the customer or by the operator. To the newest order of the subscription,
 * for its address and language, like the reminder (SubscriptionLifecycleRun).
 *
 * Never throws: the pause stands, whatever its mail does.
 */
class PauseConfirmation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function send(PauseScheduled $pause): bool
    {
        try {
            $order = $this->connection->fetchAssociative(
                'SELECT o.* FROM tl_consho_order o JOIN tl_consho_subscription s ON s.id = ?
                  WHERE o.id = s.firstOrder OR o.subscription = s.id
                  ORDER BY o.createdAt DESC, o.id DESC LIMIT 1',
                [$pause->subscriptionId],
            );
            $viewUrl = false === $order ? null : $this->links->forMail($order);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The pause confirmation of subscription %s was not sent: %s', $pause->subscriptionNumber, $e->getMessage()), ['exception' => $e]);

            return false;
        }

        if (false === $order || null === $viewUrl) {
            $this->logger->warning(\sprintf('The pause confirmation of subscription %s was not sent: the shop has no order page.', $pause->subscriptionNumber));

            return false;
        }

        return $this->mail->subscriptionPaused((int) $order['id'], $pause->subscriptionNumber, $pause->from, $pause->until, $viewUrl);
    }
}

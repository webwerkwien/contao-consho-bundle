<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrames;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\MailText;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;

/**
 * "Neuen Link anfordern" on the order page (D-6, T4).
 *
 * Since D-6 the links to the order page expire (TokenPurpose). An expired
 * link must not be a dead end — Block A (3.4) warned of exactly that: "the
 * customer meets a broken page and has to telephone". So the order page,
 * opened without a valid link, offers to send a fresh one.
 *
 * ── The mailbox is the proof ──────────────────────────────────────────────
 *
 * Order number AND address have to match an order of this shop, as in the
 * withdrawal form (D-2, W2). The link goes to the address OF THE ORDER, never
 * to one typed here — so knowing both gains nothing but a mail to the owner.
 *
 * ── One answer ────────────────────────────────────────────────────────────
 *
 * Found or not, the caller shows the same sentence ("if the details match
 * …"). "Not found" would tell a guesser which pairs exist.
 *
 * ── Limited per IP AND per address ────────────────────────────────────────
 *
 * Every request counts, found or not — counting only the hits would give
 * the answer away through the limit. Per IP against guessing, per address
 * against flooding one mailbox from many IPs (the D-4 lesson, S1).
 */
class OrderLinkRequest
{
    public const TEMPLATE = '@Contao/mail/consho_order_link.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly OrderPageLink $links,
        private readonly OrderConfirmationSourceInterface $shops,
        private readonly MailTransportFactoryInterface $transports,
        private readonly MailRenderer $renderer,
        #[Autowire(service: 'consho.rate_limit.order_link_ip')]
        private readonly RateLimiterFactoryInterface $perIp,
        #[Autowire(service: 'consho.rate_limit.order_link_address')]
        private readonly RateLimiterFactoryInterface $perAddress,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // GARAN part 2 (L3): logo and sender in the customer's mail. Optional for the tests.
        private readonly MailFrames|null $frames = null,
    ) {
    }

    public function request(int $shopId, string $orderNumber, string $email, string $ip): OrderLinkOutcome
    {
        $orderNumber = trim($orderNumber);
        $email = trim($email);

        if ('' === $orderNumber || '' === $email) {
            return OrderLinkOutcome::Missing;
        }

        // Both limiters consume on every request: `&&` would skip the second
        // once the first refuses, and the address would never be counted.
        $ipAccepted = $this->perIp->create('ip-'.$ip)->consume()->isAccepted();
        $addressAccepted = $this->perAddress->create('mail-'.hash('sha256', mb_strtolower($email)))->consume()->isAccepted();

        if (!$ipAccepted || !$addressAccepted) {
            return OrderLinkOutcome::TooMany;
        }

        // An anonymised order has no address left (D-5) and matches nothing.
        $order = $this->connection->fetchAssociative(
            "SELECT id, shop, language, orderNumber, email, firstname, lastname FROM tl_consho_order WHERE shop = ? AND orderNumber = ? AND email <> '' AND LOWER(email) = LOWER(?)",
            [$shopId, $orderNumber, $email],
        );

        if (false !== $order) {
            $this->send($order);
        }

        return OrderLinkOutcome::Requested;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function send(array $order): void
    {
        try {
            $shop = $this->shops->shop((int) $order['shop']) ?? throw new \RuntimeException('the shop does not exist');
            $transportRow = $shop['transport'] ?? throw new \RuntimeException('the shop has no mail configuration');
            $link = $this->links->forMail($order) ?? throw new \RuntimeException('the shop has no order page');
            $name = trim($order['firstname'].' '.$order['lastname']);
            $language = '' !== (string) $order['language'] ? (string) $order['language'] : (string) $shop['fallbackLanguage'];
            $frame = $this->frames?->of((int) $order['shop'], $language);

            $mail = $this->renderer->render(self::TEMPLATE, [
                'name' => $name,
                'number' => (string) $order['orderNumber'],
                'link' => $link,
            ], $language, $frame);

            $email = (new Email())
                ->from(new Address((string) ($transportRow['senderAddress'] ?? ''), (string) ($transportRow['senderName'] ?? '')))
                ->to(new Address((string) $order['email'], $name))
                ->subject($mail->subject)
                ->html($mail->body)
                ->text(MailText::fromHtml($mail->body));
            $frame?->embedInto($email);

            $this->transports->create($transportRow)->send($email);
        } catch (\Throwable $e) {
            // Number, not address (D-5, L8). The visitor still reads the same
            // sentence: a failure here must not answer "found".
            $this->logger->error(\sprintf('A new link to order %s could not be sent: %s', $order['orderNumber'], $e->getMessage()), ['exception' => $e]);
        }
    }
}

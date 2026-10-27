<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Webwerkwien\ContaoConshoBundle\Mail\SenderAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrames;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\MailText;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;

/**
 * Sends "wieder lieferbar" (Block G, G12, G15) — hourly, for every wish whose
 * article can be bought again.
 *
 * ── Asked, not triggered ─────────────────────────────────────────────────
 *
 * The cron asks Availability rather than each booking announcing itself.
 * Stock comes back by many roads — a booking by hand, a cancellation, a
 * return, codes pasted into a key pool, "sell without stock" switched on —
 * and a trigger in each would be the one forgotten in the next.
 *
 * ── Exactly one mail ─────────────────────────────────────────────────────
 *
 * The wish is deleted once the mail has left. A mail that could not be sent
 * keeps the wish for the next hour, and is logged without the address (the
 * log is Contao's, an erasure could not reach it — D-5, L8).
 *
 * To the account's address AT SENDING, never a stored one; a member who is
 * gone or disabled gets nothing, and the wish goes.
 *
 * The link leads to the product page and carries a token of
 * EmailConfirmation (G15): the page offers to confirm the address, by button,
 * signed in as this account (GuestOrderConfirmation's rules).
 */
#[AsCronJob('hourly')]
final class StockNoticeCron
{
    public const TEMPLATE = '@Contao/mail/consho_stock_available.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Availability $availability,
        private readonly OrderConfirmationSourceInterface $shops,
        private readonly MailTransportFactoryInterface $transports,
        private readonly MailRenderer $renderer,
        private readonly ProductUrlGenerator $urls,
        private readonly EmailConfirmation $confirmation,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // GARAN part 2 (L3): logo and sender in the customer's mail. Optional for the tests.
        private readonly MailFrames|null $frames = null,
    ) {
    }

    public function __invoke(): void
    {
        // Wishes of members who are gone or disabled go without a mail, and
        // so do wishes for an article that no longer exists (G).
        $this->connection->executeStatement(
            "DELETE FROM tl_consho_stock_notice
              WHERE NOT EXISTS (SELECT 1 FROM tl_member m WHERE m.id = tl_consho_stock_notice.member AND m.disable = 0 AND m.email <> '')
                 OR NOT EXISTS (SELECT 1 FROM tl_consho_product p WHERE p.id = tl_consho_stock_notice.article)
                 OR NOT EXISTS (SELECT 1 FROM tl_consho_product p WHERE p.id = tl_consho_stock_notice.main)",
        );

        $wishes = $this->connection->fetchAllAssociative(
            'SELECT n.id, n.article, n.main, n.member, n.shop, n.root, n.language, m.email, p.title
               FROM tl_consho_stock_notice n
               JOIN tl_member m ON m.id = n.member
               JOIN tl_consho_product p ON p.id = n.main
              ORDER BY n.id',
        );

        if ([] === $wishes) {
            return;
        }

        $stock = $this->availability->of(array_values(array_unique(array_map(static fn (array $wish): int => (int) $wish['article'], $wishes))));

        foreach ($wishes as $wish) {
            $article = (int) $wish['article'];

            if (!($stock[$article] ?? null)?->state->purchasable()) {
                continue;
            }

            if (!$this->send($wish)) {
                continue;
            }

            $this->connection->delete('tl_consho_stock_notice', ['id' => (int) $wish['id']]);
        }
    }

    /**
     * @param array<string, mixed> $wish
     */
    private function send(array $wish): bool
    {
        try {
            $url = $this->urls->generate((int) $wish['main'], (int) $wish['root'], UrlGeneratorInterface::ABSOLUTE_URL, (int) $wish['article'] !== (int) $wish['main'] ? (int) $wish['article'] : 0);
            $shop = $this->shops->shop((int) $wish['shop']) ?? throw new StockNoticeNotSendable('the shop does not exist');
            $transportRow = $shop['transport'] ?? throw new StockNoticeNotSendable('the shop has no mail configuration');
            $email = (string) $wish['email'];
            $token = $this->confirmation->issue(ConfirmationPurpose::StockNotice, $email, ['tl_member' => [(int) $wish['member']]]);
            $link = $url.(str_contains($url, '?') ? '&' : '?').http_build_query([EmailConfirmation::PARAM => $token]);

            $language = '' !== (string) $wish['language'] ? (string) $wish['language'] : $shop['fallbackLanguage'];
            $frame = $this->frames?->of((int) $wish['shop'], $language);

            $mail = $this->renderer->render(self::TEMPLATE, [
                // Input-encoded in the database; the mail template escapes.
                'product' => html_entity_decode((string) $wish['title'], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                'link' => $link,
            ], $language, $frame);

            $message = (new Email())
                ->from(SenderAddress::of($transportRow))
                ->to(new Address($email))
                ->subject($mail->subject)
                ->html($mail->body)
                ->text(MailText::fromHtml($mail->body));
            $frame?->embedInto($message);

            $this->transports->create($transportRow)->send($message);
        } catch (ProductPageMissing) {
            // The start point has no product page (any more): no mail can
            // ever lead anywhere. The wish goes, once, with a line in the log
            // (G) — not an error every hour. Inside this try, so
            // any other trouble with the address is caught below and does not
            // stop the cron for everybody.
            $this->logger->error(\sprintf('A "back in stock" wish for article %d was dropped: its start point %d has no product page.', (int) $wish['article'], (int) $wish['root']));
            $this->connection->delete('tl_consho_stock_notice', ['id' => (int) $wish['id']]);

            return false;
        } catch (\Throwable $e) {
            // The exception's class and our own words only (G): an
            // invalid address or a refusing mail server puts the address into
            // the message, and the log is Contao's — no erasure reaches it.
            $this->logger->error(\sprintf(
                'A "back in stock" mail for article %d could not be sent (%s)%s',
                (int) $wish['article'],
                (new \ReflectionClass($e))->getShortName(),
                $e instanceof StockNoticeNotSendable ? ': '.$e->getMessage() : '.',
            ));

            return false;
        }

        return true;
    }
}

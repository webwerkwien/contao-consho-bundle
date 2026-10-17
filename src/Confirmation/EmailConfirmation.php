<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Confirmation;

use Contao\CoreBundle\OptIn\OptInInterface;
use Contao\CoreBundle\OptIn\OptInTokenAlreadyConfirmedException;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\CoreBundle\OptIn\OptInTokenNoLongerValidException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Webwerkwien\ContaoConshoBundle\Mail\SenderAddress;
use Symfony\Component\Mime\Email;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrames;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\MailText;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;

/**
 * Confirming an e-mail address by a link in a mail (D-4, K3a) — one service
 * for every place in Consho that needs to know an address belongs to whoever
 * is asking.
 *
 * Michael, 2026-09-28: „baue die Funktion für die Bestätigungsmail so, dass
 * sie von mehreren Diensten in Anspruch genommen werden kann" — a function of
 * Consho, not a module of its own.
 *
 *  - The TOKEN is Contao's opt-in (`contao.opt_in`): valid 24 hours, kept as
 *    proof after the confirmation, removed by Contao's own cron, listed in the
 *    back end under "Double-Opt-In". Nothing of it is rebuilt here.
 *  - The MAIL is Consho's: the shop's transport and sender, the shop's
 *    template, like every other shop mail. Contao's OptInToken::send() would
 *    go out through the system mailer instead, from another sender.
 *
 * ── Two steps, not one ────────────────────────────────────────────────────
 *
 * check() reads a token, confirm() confirms it. The link in the mail must
 * never confirm by being OPENED: mail security filters (Microsoft's Safe Links
 * and the like) open every link to inspect it. A confirmation on GET would be
 * given by the victim's mailbox for whoever requested it. The caller shows a
 * button on the linked page, and only its POST calls confirm().
 */
class EmailConfirmation
{
    /** The query parameter carrying the token on the linked page. */
    public const PARAM = 'consho_confirm';

    public const TEMPLATE = '@Contao/mail/consho_email_confirmation.html.twig';

    public function __construct(
        #[Autowire(service: 'contao.opt_in')]
        private readonly OptInInterface $optIn,
        private readonly OrderConfirmationSourceInterface $shops,
        private readonly MailTransportFactoryInterface $transports,
        private readonly MailRenderer $renderer,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // GARAN part 2 (L3): logo and sender in the customer's mail. Optional for the tests.
        private readonly MailFrames|null $frames = null,
    ) {
    }

    /**
     * Sends the mail with the link.
     *
     * @param array<string, list<int>> $related    what is being confirmed, e.g. ['tl_member' => [5]]
     * @param string                   $landingUrl the page that handles the link; the token is appended
     *
     * @return bool whether the mail left
     */
    public function request(ConfirmationPurpose $purpose, int $shopId, string $email, string $name, string $language, array $related, string $landingUrl): bool
    {
        try {
            $shop = $this->shops->shop($shopId) ?? throw new \RuntimeException('the shop does not exist');
            $transportRow = $shop['transport'] ?? throw new \RuntimeException('the shop has no mail configuration');

            $token = $this->optIn->create($purpose->prefix(), $email, $related);
            // The token goes into the query, before an anchor the caller may
            // have given (MyOrdersFlow lands on its confirmation box).
            [$address, $anchor] = array_pad(explode('#', $landingUrl, 2), 2, null);
            $separator = str_contains($address, '?') ? '&' : '?';
            $link = $address.$separator.http_build_query([self::PARAM => $token->getIdentifier()]).(null !== $anchor ? '#'.$anchor : '');

            $language = '' !== $language ? $language : $shop['fallbackLanguage'];
            $frame = $this->frames?->of($shopId, $language);

            $mail = $this->renderer->render(self::TEMPLATE, [
                'purpose' => $purpose->value,
                'name' => $name,
                'email' => $email,
                'link' => $link,
            ], $language, $frame);

            $message = (new Email())
                ->from(SenderAddress::of($transportRow))
                ->to(new Address($email, $name))
                ->subject($mail->subject)
                ->html($mail->body)
                ->text(MailText::fromHtml($mail->body));
            $frame?->embedInto($message);

            $this->transports->create($transportRow)->send($message);
        } catch (\Throwable $e) {
            // Without the address (D-5, L8): the log is kept by Contao, not
            // by Consho, and an erasure could never reach it.
            $this->logger->error(\sprintf('A confirmation mail (%s) could not be sent: %s', $purpose->value, $e->getMessage()), ['exception' => $e]);

            return false;
        }

        return true;
    }

    /**
     * A token without a mail of its own — for a link inside another mail
     * (Block G, G15: "wieder lieferbar"). Same token, same check, same rules;
     * only the mail is the caller's.
     *
     * @param array<string, list<int>> $related what is being confirmed, e.g. ['tl_member' => [5]]
     *
     * @return string the identifier, for the query parameter PARAM
     */
    public function issue(ConfirmationPurpose $purpose, string $email, array $related): string
    {
        return $this->optIn->create($purpose->prefix(), $email, $related)->getIdentifier();
    }

    /**
     * What the token stands for, without confirming anything.
     */
    public function check(ConfirmationPurpose $purpose, string $identifier): ConfirmationCheck
    {
        $token = $this->find($purpose, $identifier);

        if (null === $token) {
            return ConfirmationCheck::invalid();
        }

        if ($token->isConfirmed()) {
            return ConfirmationCheck::of(ConfirmationStatus::Confirmed, $token->getEmail(), $this->related($token));
        }

        return $token->isValid()
            ? ConfirmationCheck::of(ConfirmationStatus::Pending, $token->getEmail(), $this->related($token))
            : ConfirmationCheck::invalid();
    }

    /**
     * Confirms the token. Only from a POST — see the class comment.
     *
     * Confirmed twice is confirmed: the second click of a customer who was
     * not sure the first one worked must not read as a failure.
     */
    public function confirm(ConfirmationPurpose $purpose, string $identifier): ConfirmationCheck
    {
        $token = $this->find($purpose, $identifier);

        if (null === $token) {
            return ConfirmationCheck::invalid();
        }

        try {
            $token->confirm();
        } catch (OptInTokenAlreadyConfirmedException) {
            // Falls through: confirmed is confirmed.
        } catch (OptInTokenNoLongerValidException) {
            return ConfirmationCheck::invalid();
        }

        return ConfirmationCheck::of(ConfirmationStatus::Confirmed, $token->getEmail(), $this->related($token));
    }

    private function find(ConfirmationPurpose $purpose, string $identifier): OptInTokenInterface|null
    {
        // Another purpose's token, or anything that is not one of ours, is
        // not looked up at all.
        if (!str_starts_with($identifier, $purpose->prefix().'-')) {
            return null;
        }

        return $this->optIn->find($identifier);
    }

    /**
     * @return array<string, list<int>>
     */
    private function related(OptInTokenInterface $token): array
    {
        $related = [];

        foreach ($token->getRelatedRecords() as $table => $ids) {
            $related[(string) $table] = array_values(array_map('intval', (array) $ids));
        }

        return $related;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\CoreBundle\OptIn\OptInInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Mail\SenderAddress;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrames;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\MailText;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;

/**
 * "Kundenkonto anlegen" on the order page of a guest order (K1).
 *
 * ── Contao sets the password ──────────────────────────────────────────────
 *
 * The account is created closed and without a password (K1-6), and the mail
 * carries a `pw-` token of Contao's own opt-in to the page with Contao's
 * "Lost password" module (K1-2). That module checks nothing but the token —
 * valid, one tl_member row, the same address — so it sets the first password
 * of a closed account as it sets a new one of an open account. Its hook
 * `setNewPassword` opens the account (AccountFromOrderActivation): the click
 * on the link is the proof of the mailbox. An account never opened goes once
 * the token has expired (AccountFromOrderCron).
 *
 * Why not a password form of our own: Contao's form brings the password
 * rules, the hashing, the versions and the session check, and the operator
 * styles it once for both uses.
 *
 * ── One answer ────────────────────────────────────────────────────────────
 *
 * An address with an account already gets a mail that says so and links
 * "Lost password" (K1-5); the page answers the same as for a new account.
 * Whoever holds the order link learns nothing about other accounts, and the
 * owner of the address learns it in their own mailbox.
 *
 * ── Limited per IP and per address ────────────────────────────────────────
 *
 * As "Neuen Link anfordern" (OrderLinkRequest): every request counts.
 */
class AccountFromOrder
{
    public const TEMPLATE_CREATE = '@Contao/mail/consho_account_create.html.twig';

    public const TEMPLATE_EXISTS = '@Contao/mail/consho_account_exists.html.twig';

    /** `tl_member.username` is a varchar(64), and the username is the address (K1-4). */
    private const USERNAME_LENGTH = 64;

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'contao.opt_in')]
        private readonly OptInInterface $optIn,
        private readonly OrderPageLink $links,
        private readonly AccountPasswordPage $passwordPages,
        private readonly OrderConfirmationSourceInterface $shops,
        private readonly MailTransportFactoryInterface $transports,
        private readonly MailRenderer $renderer,
        #[Autowire(service: 'consho.rate_limit.account_ip')]
        private readonly RateLimiterFactoryInterface $perIp,
        #[Autowire(service: 'consho.rate_limit.account_address')]
        private readonly RateLimiterFactoryInterface $perAddress,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly MailFrames|null $frames = null,
    ) {
    }

    /**
     * Whether the order page shows the box: a guest order with an address
     * that fits the username, in a shop whose start point names the "Lost
     * password" page.
     *
     * @param array<string, string> $order a row of tl_consho_order
     */
    public function offered(array $order): bool
    {
        $email = trim($order['email'] ?? '');

        return '0' === (string) ($order['member'] ?? '0')
            && '' !== $email
            && mb_strlen($email) <= self::USERNAME_LENGTH
            && null !== $this->passwordPages->of((int) $this->shops->rootOf((int) ($order['shop'] ?? 0), (string) ($order['language'] ?? '')))
            && null !== $this->links->passwordPage($order);
    }

    /**
     * @param array<string, string> $order a row of tl_consho_order
     */
    public function request(array $order, string $ip, int $now): AccountRequestOutcome
    {
        if (!$this->offered($order)) {
            return AccountRequestOutcome::Unavailable;
        }

        $email = trim($order['email']);

        // Both limiters consume on every request, as in OrderLinkRequest.
        $ipAccepted = $this->perIp->create('ip-'.$ip)->consume()->isAccepted();
        $addressAccepted = $this->perAddress->create('mail-'.hash('sha256', mb_strtolower($email)))->consume()->isAccepted();

        if (!$ipAccepted || !$addressAccepted) {
            return AccountRequestOutcome::TooMany;
        }

        $existing = $this->existing($email);

        // The address is somebody else's USERNAME, not the address of an
        // account: nothing to tell its owner and no account to create. The
        // page answers the same; the log says why no mail went.
        if (false !== $existing && 0 !== strcasecmp((string) $existing['email'], $email)) {
            $this->logger->warning(\sprintf('No account was created for order %s: its address is the username of another account.', $order['orderNumber'] ?? ''));

            return AccountRequestOutcome::Requested;
        }

        if (false !== $existing && (int) $existing['consho_accountPending'] < 1) {
            $this->send($order, self::TEMPLATE_EXISTS, [
                'username' => (string) ($existing['username'] ?? '') ?: $email,
                'link' => (string) $this->links->passwordPage($order),
            ]);

            return AccountRequestOutcome::Requested;
        }

        // A pending account from an earlier request gets a fresh link; its
        // time runs from the first request (the cron's 24 hours).
        $memberId = false === $existing ? $this->create($order, $email, $now) : (int) $existing['id'];

        if (null === $memberId) {
            return AccountRequestOutcome::Requested;
        }

        $token = $this->optIn->create('pw', $email, ['tl_member' => [$memberId]]);

        $this->send($order, self::TEMPLATE_CREATE, [
            'username' => $email,
            'link' => \sprintf('%s?%s', $this->links->passwordPage($order), http_build_query(['token' => $token->getIdentifier()])),
        ]);

        return AccountRequestOutcome::Requested;
    }

    /**
     * Closed, without a password and without a group until the password is
     * set (K1-6). The data of the order (K1-3): billing address, phone, VAT
     * ID, language — and the delivery address where the order has one of its
     * own. No date of birth: the order has none.
     *
     * Cut to the widths of tl_member: an order column may be wider than the
     * member field (an imported order, a longer state name), and a strict
     * database refuses the whole row rather than a character.
     *
     * Null where a parallel request created the account in between (the
     * username is unique): that request sends the mail.
     *
     * @param array<string, string> $order
     */
    private function create(array $order, string $email, int $now): int|null
    {
        $copied = [
            'firstname' => [$order['firstname'] ?? '', 255],
            'lastname' => [$order['lastname'] ?? '', 255],
            'company' => [$order['company'] ?? '', 255],
            'street' => [$order['billingStreet'] ?? '', 255],
            'postal' => [$order['billingPostal'] ?? '', 32],
            'city' => [$order['billingCity'] ?? '', 255],
            'state' => [$order['billingState'] ?? '', 64],
            'country' => [strtoupper($order['billingCountry'] ?? ''), 2],
            'phone' => [$order['phone'] ?? '', 64],
            'language' => [$order['language'] ?? '', 64],
            'consho_vatId' => [$order['vatId'] ?? '', 32],
        ];

        // An empty delivery street means "as the billing address" (InvoiceView).
        if ('' !== ($order['shippingStreet'] ?? '')) {
            $copied += [
                'consho_shippingCompany' => [$order['shippingCompany'] ?? '', 255],
                'consho_shippingFirstname' => [$order['shippingFirstname'] ?? '', 255],
                'consho_shippingLastname' => [$order['shippingLastname'] ?? '', 255],
                'consho_shippingStreet' => [$order['shippingStreet'], 255],
                'consho_shippingPostal' => [$order['shippingPostal'] ?? '', 32],
                'consho_shippingCity' => [$order['shippingCity'] ?? '', 255],
                'consho_shippingState' => [$order['shippingState'] ?? '', 64],
                'consho_shippingCountry' => [strtoupper($order['shippingCountry'] ?? ''), 2],
            ];
        }

        $row = array_map(static fn (array $field): string => mb_substr($field[0], 0, $field[1]), $copied) + [
            'tstamp' => $now,
            'dateAdded' => $now,
            'email' => $email,
            'login' => 1,
            'username' => $email,
            'password' => '',
            'disable' => 1,
            'consho_accountPending' => $now,
        ] + self::vatCheckOf($order);

        try {
            $this->connection->insert('tl_member', $row);
        } catch (UniqueConstraintViolationException $e) {
            // Only the account a parallel request created is no error.
            if (false !== $this->existing($email)) {
                return null;
            }

            throw $e;
        }

        return (int) $this->connection->lastInsertId();
    }

    /**
     * The order's VIES verdict goes along with its VAT ID — the account is
     * opened without asking VIES again, and the net price groups follow on
     * opening (AccountFromOrderActivation). "Unavailable" is no verdict and
     * stays behind.
     *
     * The verdict only, not the evidence: the order keeps neither the moment
     * of the check (a basket may wait for days between check and purchase)
     * nor the VAT ID it asked with, and the shop's may have changed since.
     * Without a time the result is never reused — the next check that needs
     * one asks VIES again (MemberVatId).
     *
     * @param array<string, string> $order
     *
     * @return array<string, string>
     */
    private static function vatCheckOf(array $order): array
    {
        $status = $order['vatIdStatus'] ?? '';

        if ('' === trim($order['vatId'] ?? '') || !\in_array($status, ['valid', 'invalid'], true)) {
            return [];
        }

        return ['consho_vatIdStatus' => $status];
    }

    /**
     * The account under this address or with it as username, the address
     * match first.
     *
     * @return array<string, mixed>|false
     */
    private function existing(string $email): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT id, email, username, consho_accountPending FROM tl_member WHERE LOWER(email) = LOWER(?) OR username = ? ORDER BY CASE WHEN LOWER(email) = LOWER(?) THEN 0 ELSE 1 END, id',
            [$email, $email, $email],
        );
    }

    /**
     * @param array<string, string> $order
     * @param array<string, string> $context
     */
    private function send(array $order, string $template, array $context): void
    {
        try {
            $shop = $this->shops->shop((int) $order['shop']) ?? throw new \RuntimeException('the shop does not exist');
            $transportRow = $shop['transport'] ?? throw new \RuntimeException('the shop has no mail configuration');
            $name = trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? ''));
            $language = '' !== ($order['language'] ?? '') ? $order['language'] : (string) $shop['fallbackLanguage'];
            $frame = $this->frames?->of((int) $order['shop'], $language);

            $mail = $this->renderer->render($template, ['name' => $name, ...$context], $language, $frame);

            $email = (new Email())
                ->from(SenderAddress::of($transportRow))
                ->to(new Address(trim($order['email']), $name))
                ->subject($mail->subject)
                ->html($mail->body)
                ->text(MailText::fromHtml($mail->body));
            $frame?->embedInto($email);

            $this->transports->create($transportRow)->send($email);
        } catch (\Throwable $e) {
            // The number, not the address (D-5, L8).
            $this->logger->error(\sprintf('The account mail for order %s could not be sent: %s', $order['orderNumber'] ?? '', $e->getMessage()), ['exception' => $e]);
        }
    }
}

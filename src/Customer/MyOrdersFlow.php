<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\OrderController;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;

/**
 * What "Meine Bestellungen" does with a request (D-4, K3) — everything but
 * finding the page, the shop and the member, which is MyOrdersController's.
 *
 * Its own class so the rules can be tested without Contao's page objects
 * (Fable review D-4, B1): the element is the one place where a member id,
 * an order id from the query and a token meet.
 *
 *  - `consho_open=<id>`: only an order MemberOrders::owns() gets a token —
 *    an hour, confirming no address — and a redirect to the order page.
 *  - POST `request`: the confirmation mail, limited per account AND per
 *    address. Without a name: the account's name is whatever its holder
 *    typed, and the addressee may be somebody else (Fable review D-4, S1).
 *  - POST `confirm`: GuestOrderConfirmation decides. Never on a GET.
 *  - `consho_my_orders=<notice>`: only the known keys (review, S3) — a free
 *    text in the query must not end up as a sentence on the shop's page.
 */
class MyOrdersFlow
{
    public const OPEN_PARAM = 'consho_open';

    public const NOTICE_PARAM = 'consho_my_orders';

    public const ACTION = 'consho_my_orders_action';

    public const TOKEN_FIELD = 'consho_confirm_token';

    /** The anchor of the confirmation box, so the mail's link lands on it. */
    public const ANCHOR = 'consho-confirm';

    /** An order page opened from here stays open for an hour. */
    public const OPEN_LIFETIME = 3600;

    public const NOTICES = ['sent', 'notSent', 'tooMany', 'confirmed', 'notFound', 'noOrderPage'];

    public function __construct(
        private readonly MemberOrders $orders,
        private readonly GuestOrderConfirmation $confirmation,
        private readonly TokenGenerator $tokens,
        #[Autowire(service: 'consho.rate_limit.email_confirmation')]
        private readonly RateLimiterFactoryInterface $limiter,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    /**
     * @param string|null $orderPage the shop's order page, null where it has none
     * @param string      $here      this page's address without query
     *
     * @return array{redirect: string}|array{view: array<string, mixed>|null} null: render nothing
     */
    public function handle(Request $request, int $shopId, string|null $orderPage, int $memberId, string $email, string $language, string $here, int $now): array
    {
        if ($request->isMethod('POST')) {
            $redirect = $this->act($request, $shopId, $memberId, $email, $language, $here, $now);

            if (null !== $redirect) {
                return ['redirect' => $redirect];
            }
        }

        $open = $request->query->getInt(self::OPEN_PARAM);

        if ($open > 0) {
            return ['redirect' => $this->open($shopId, $orderPage, $memberId, $open, $here, $now)];
        }

        $token = $request->query->getString(EmailConfirmation::PARAM);
        $confirm = '' !== $token ? $this->confirmation->state($token, $memberId, $email) : null;

        // Signed out and no confirmation link: nothing to say.
        if ($memberId < 1 && null === $confirm) {
            return ['view' => null];
        }

        $notice = $request->query->getString(self::NOTICE_PARAM);

        return ['view' => [
            'confirm' => $confirm,
            'token' => $token,
            'email' => $email,
            'notice' => \in_array($notice, self::NOTICES, true) ? $notice : '',
            'signedIn' => $memberId > 0,
            'guestConfirmed' => $this->orders->guestOrdersConfirmed($memberId),
            'orders' => array_map(fn (array $order): array => [
                'number' => $order['orderNumber'],
                'date' => (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($order['createdAt']),
                'total' => $this->money->format($order['grandTotal'], $order['currency'], $language),
                'guest' => $order['guest'],
                'url' => $here.'?'.http_build_query([self::OPEN_PARAM => $order['id']]),
            ], $this->orders->list($shopId, $memberId)),
        ]];
    }

    /**
     * Both buttons end in a redirect (303 by the caller), so a reload sends
     * nothing twice.
     */
    private function act(Request $request, int $shopId, int $memberId, string $email, string $language, string $here, int $now): string|null
    {
        $action = $request->request->getString(self::ACTION);

        if ('request' === $action && $memberId > 0) {
            $notice = 'tooMany';

            // Both must allow it: per account, and per address — new accounts
            // are cheap, so the account alone would not hold anybody back.
            $perAccount = $this->limiter->create('member-'.$memberId)->consume()->isAccepted();
            $perAddress = $this->limiter->create('mail-'.hash('sha256', mb_strtolower(trim($email))))->consume()->isAccepted();

            if ($perAccount && $perAddress) {
                $notice = $this->confirmation->request($shopId, $memberId, $email, $language, $here.'#'.self::ANCHOR) ? 'sent' : 'notSent';
            }

            return $here.'?'.http_build_query([self::NOTICE_PARAM => $notice]);
        }

        if ('confirm' === $action) {
            $token = $request->request->getString(self::TOKEN_FIELD);

            // Not confirmed: back to the link's page, which says why.
            return 'confirmed' === $this->confirmation->confirm($token, $memberId, $email, $now)
                ? $here.'?'.http_build_query([self::NOTICE_PARAM => 'confirmed'])
                : $here.'?'.http_build_query([EmailConfirmation::PARAM => $token]).'#'.self::ANCHOR;
        }

        return null;
    }

    private function open(int $shopId, string|null $orderPage, int $memberId, int $orderId, string $here, int $now): string
    {
        if ($memberId < 1 || !$this->orders->owns($shopId, $memberId, $orderId)) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'notFound']);
        }

        if (null === $orderPage) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'noOrderPage']);
        }

        $token = $this->tokens->issue($orderId, TokenPurpose::ViewOrder, $now + self::OPEN_LIFETIME, false);

        return $orderPage.'?'.http_build_query([OrderController::TOKEN_PARAM => $token]);
    }
}

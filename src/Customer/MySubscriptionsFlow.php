<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\OrderController;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseConfirmation;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalContext;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalOffer;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPauseRefused;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;

/**
 * What "Meine Abos" does with a request (Abo-7, P60) — everything but finding
 * the page, the shop and the member, which is MySubscriptionsController's.
 *
 * Every step asks first whether the subscription is the signed-in member's in
 * this shop (a subscription always has a member, Abo-3); an id from the query
 * is never enough.
 *
 *  - the list: number, product, model, state, next billing, end;
 *  - `consho_open_subscription=<id>`: the order page of the purchase, with a
 *    token for an hour, like "Meine Bestellungen";
 *  - `consho_cancel_subscription=<id>`: the confirmation page of
 *    "Verträge hier kündigen", without asking for number and address;
 *  - POST confirm: CancellationForm records it, then the signed result page
 *    (§ 312k Abs. 3), on this page;
 *  - POST `consho_renew_subscription=<id>` (Abo-7b, P67): "Lizenz verlängern"
 *    — asked again, the line into the member's basket, on to the basket page.
 *    The link is only there when the renewal is: renewable and offered with
 *    a price: nothing is offered that does not exist anyway.
 *  - `consho_pause_subscription=<id>` (Abo pausieren, P101): the page to
 *    choose a length; POST confirm announces it (asked again under the
 *    lock), mails "Pause bestätigt" and comes back with a notice. The link
 *    only where a pause can be chosen.
 *  - POST `consho_withdraw_pause=<id>` (P107): an announced pause taken back.
 */
class MySubscriptionsFlow
{
    public const OPEN_PARAM = 'consho_open_subscription';

    public const CANCEL_PARAM = 'consho_cancel_subscription';

    public const NOTICE_PARAM = 'consho_my_subscriptions';

    public const RENEW_PARAM = 'consho_renew_subscription';

    public const PAUSE_PARAM = 'consho_pause_subscription';

    public const WITHDRAW_PAUSE_PARAM = 'consho_withdraw_pause';

    public const NOTICES = ['notFound', 'noOrderPage', 'renewalUnavailable', 'paused', 'pauseWithdrawn', 'pauseUnavailable'];

    public function __construct(
        private readonly Connection $connection,
        private readonly CancellationForm $form,
        private readonly TokenGenerator $tokens,
        private readonly CancellationTerm $term,
        // Abo-7b: optional for the tests of Abo-7; the container wires both.
        private readonly SubscriptionRenewal|null $renewal = null,
        private readonly DbalCartRepository|null $carts = null,
        // Abo pausieren: optional for the tests before it; the container wires both.
        private readonly SubscriptionPause|null $pauses = null,
        private readonly PauseConfirmation|null $confirmation = null,
    ) {
    }

    /**
     * @param string|null $orderPage the shop's order page, null where it has none
     * @param string      $here      this page's address without query
     * @param RenewalContext|null $renewalContext the basket's currency, country, audience and page; null: no "Lizenz verlängern"
     *
     * @return array{redirect: string}|array{view: array<string, mixed>|null} null: render nothing
     */
    public function handle(Request $request, int $shopId, string|null $orderPage, int $memberId, string $language, string $here, int $now, string $memberName = '', RenewalContext|null $renewalContext = null): array
    {
        if ($memberId < 1) {
            return ['view' => null];
        }

        $view = ['result' => null, 'form' => false, 'input' => CancellationForm::read(new Request()), 'error' => null, 'until' => '', 'contract' => '', 'subscription' => 0, 'earliest' => '', 'notice' => '', 'subscriptions' => [], 'pause' => null];

        if ($request->isMethod('POST') && $request->request->has(self::RENEW_PARAM)) {
            return ['redirect' => $this->renew($shopId, $memberId, $request->request->getInt(self::RENEW_PARAM), $language, $here, $now, $renewalContext)];
        }

        if ($request->isMethod('POST') && $request->request->has(self::WITHDRAW_PAUSE_PARAM)) {
            $subscription = $this->owned($shopId, $memberId, $request->request->getInt(self::WITHDRAW_PAUSE_PARAM));
            $withdrawn = null !== $subscription && null !== $this->pauses && $this->pauses->withdraw((int) $subscription['id'], $now);

            return ['redirect' => $here.'?'.http_build_query([self::NOTICE_PARAM => $withdrawn ? 'pauseWithdrawn' : 'pauseUnavailable'])];
        }

        if ($request->isMethod('POST') && 'confirm' === $request->request->getString('consho_pause_action')) {
            return ['redirect' => $this->pause($shopId, $memberId, $request->request->getInt(self::PAUSE_PARAM), $request->request->getInt('consho_pause_months'), $here, $now)];
        }

        if ($request->isMethod('POST') && 'confirm' === $request->request->getString('consho_cancellation_action')) {
            $subscription = $this->owned($shopId, $memberId, $request->request->getInt('consho_cancellation_subscription'));

            if (null === $subscription) {
                return ['redirect' => $here.'?'.http_build_query([self::NOTICE_PARAM => 'notFound'])];
            }

            $input = CancellationForm::read($request);
            [$error, $result] = $this->form->declare($input, (int) $subscription['id'], CancellationSource::Account, $now);

            if (null === $error && null !== $result?->id) {
                return ['redirect' => $this->form->resultUrl($request, $result->id, $now)];
            }

            return ['view' => [
                ...$view,
                'form' => true,
                'input' => $input,
                'error' => $error,
                'until' => null === $result?->effectiveAt ? '' : CancellationForm::moment($result->effectiveAt, $language),
                'contract' => self::contract($subscription),
                'subscription' => (int) $subscription['id'],
                'earliest' => $this->earliest($subscription, $language, $now),
            ]];
        }

        $open = $request->query->getInt(self::OPEN_PARAM);

        if ($open > 0) {
            return ['redirect' => $this->open($shopId, $orderPage, $memberId, $open, $here, $now)];
        }

        $result = $this->form->shown($request, $language);

        if (null !== $result) {
            return ['view' => [...$view, 'result' => $result]];
        }

        $pause = $request->query->getInt(self::PAUSE_PARAM);

        if ($pause > 0) {
            $subscription = $this->owned($shopId, $memberId, $pause);
            $options = null === $subscription || null === $this->pauses ? [] : $this->pauses->options($subscription, $now);

            if (null === $subscription || [] === $options) {
                return ['redirect' => $here.'?'.http_build_query([self::NOTICE_PARAM => null === $subscription ? 'notFound' : 'pauseUnavailable'])];
            }

            return ['view' => [...$view, 'pause' => [
                'contract' => self::contract($subscription),
                'subscription' => (int) $subscription['id'],
                'from' => CancellationForm::day((int) $subscription['periodUntil'], $language),
                'options' => array_map(fn (int $months): array => [
                    'months' => $months,
                    'until' => CancellationForm::day($this->pauses->untilFor($subscription, $months), $language),
                ], $options),
                'back' => $here,
            ]]];
        }

        $cancel = $request->query->getInt(self::CANCEL_PARAM);

        if ($cancel > 0) {
            $subscription = $this->owned($shopId, $memberId, $cancel);

            if (null === $subscription) {
                return ['redirect' => $here.'?'.http_build_query([self::NOTICE_PARAM => 'notFound'])];
            }

            // The account's name, changeable (prefilled) — the account knows it; asking again is friction.
            return ['view' => [
                ...$view,
                'form' => true,
                'input' => ['name' => trim($memberName)] + $view['input'],
                'contract' => self::contract($subscription),
                'subscription' => (int) $subscription['id'],
                'earliest' => $this->earliest($subscription, $language, $now),
            ]];
        }

        $notice = $request->query->getString(self::NOTICE_PARAM);

        // A result page past its 90 days says so (L4).
        if ($this->form->expired($request)) {
            $notice = 'expired';
        }

        return ['view' => [
            ...$view,
            'notice' => \in_array($notice, [...self::NOTICES, 'expired'], true) ? $notice : '',
            'subscriptions' => array_map(fn (array $row): array => $this->line($row, $language, $here, $now, $renewalContext), $this->list($shopId, $memberId)),
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(int $shopId, int $memberId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->connection->fetchAllAssociative(
            "SELECT s.*, i.productName,
                    EXISTS (SELECT 1 FROM tl_consho_cancellation c WHERE c.pid = s.id AND c.kind = 'extraordinary' AND c.decidedAt IS NULL) AS pendingExtraordinary,
                    (SELECT r.subscriptionNumber FROM tl_consho_subscription r WHERE r.id = s.renewedBy) AS renewedByNumber
               FROM tl_consho_subscription s
               LEFT JOIN tl_consho_order_item i ON i.id = s.orderItem
              WHERE s.shop = ? AND s.member = ?
              ORDER BY s.startedAt DESC, s.id DESC",
            [$shopId, $memberId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function owned(int $shopId, int $memberId, int $subscriptionId): array|null
    {
        if ($subscriptionId < 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT s.*, i.productName FROM tl_consho_subscription s
               LEFT JOIN tl_consho_order_item i ON i.id = s.orderItem
              WHERE s.id = ? AND s.shop = ? AND s.member = ?',
            [$subscriptionId, $shopId, $memberId],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function line(array $row, string $language, string $here, int $now, RenewalContext|null $context): array
    {
        $ended = 'ended' === (string) $row['state'];
        $endsAt = (int) ($row['endsAt'] ?? 0);
        $next = (int) $row['nextBillingAt'];
        $renews = RenewalKind::tryFrom((string) $row['renewal'])?->renews() ?? false;

        // The next billing that will really come: none once ended, none at or
        // after a known end, none at the end of a term that does not renew.
        $billed = !$ended && $next > 0 && (0 === $endsAt || $next < $endsAt)
            && ('indefinite' === (string) $row['phase'] || $next < (int) $row['minimumEndsAt'] || $renews);

        return [
            'number' => (string) $row['subscriptionNumber'],
            'product' => (string) ($row['productName'] ?? ''),
            'model' => (string) $row['ruleTitle'],
            'state' => match (true) {
                $ended => 'ended',
                $endsAt > 0 => 'ending',
                // Resting first: also a renewal not started yet can rest for
                // its predecessor's invoice.
                'suspended' === (string) $row['state'] => 'suspended',
                'paused' === (string) $row['state'] => 'paused',
                // A renewal that starts where the old licence ends (Abo-7b).
                (int) $row['startedAt'] > $now => 'upcoming',
                default => 'active',
            },
            'nextBilling' => $billed ? CancellationForm::day($next, $language) : '',
            'endsAt' => $endsAt > 0 ? CancellationForm::day($endsAt, $language) : '',
            'startsAt' => CancellationForm::day((int) $row['startedAt'], $language),
            'pending' => (bool) $row['pendingExtraordinary'],
            'orderUrl' => $here.'?'.http_build_query([self::OPEN_PARAM => $row['id']]),
            'cancelUrl' => $ended ? '' : $here.'?'.http_build_query([self::CANCEL_PARAM => $row['id']]),
            // Abo-7b: the conditions with today's price, or null — no link.
            'renew' => $this->offer($row, $language, $now, $context)?->terms,
            'renewedBy' => (string) ($row['renewedByNumber'] ?? ''),
            'id' => (int) $row['id'],
            // Abo pausieren (P101, P107, P111).
            'pauseUntil' => (int) ($row['pauseUntil'] ?? 0) > 0 ? CancellationForm::day((int) $row['pauseUntil'], $language) : '',
            'pauseAnnounced' => SubscriptionPause::announced($row, $now) ? CancellationForm::day((int) $row['pauseFrom'], $language) : '',
            'pauseWithdrawable' => SubscriptionPause::withdrawable($row, $now),
            'pauseUrl' => null !== $this->pauses && [] !== $this->pauses->options($row, $now) ? $here.'?'.http_build_query([self::PAUSE_PARAM => $row['id']]) : '',
        ];
    }

    /**
     * "Pause bestätigen" (P101): asked again under the lock — the page was
     * drawn a while ago —, then the mail, then back to the list with a notice.
     */
    private function pause(int $shopId, int $memberId, int $subscriptionId, int $months, string $here, int $now): string
    {
        $subscription = $this->owned($shopId, $memberId, $subscriptionId);

        if (null === $subscription) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'notFound']);
        }

        try {
            $scheduled = $this->pauses?->schedule((int) $subscription['id'], $months, 'account', $now);
        } catch (SubscriptionPauseRefused) {
            $scheduled = null;
        }

        if (null === $scheduled) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'pauseUnavailable']);
        }

        $this->confirmation?->send($scheduled);

        return $here.'?'.http_build_query([self::NOTICE_PARAM => 'paused']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function offer(array $row, string $language, int $now, RenewalContext|null $context): RenewalOffer|null
    {
        if (null === $context || null === $this->renewal || null === $this->carts || !$this->renewal->renewable($row, $now)) {
            return null;
        }

        return $this->renewal->offer($row, $context, $language, $now);
    }

    /**
     * "Lizenz verlängern" clicked (P67): everything asked again — the link was
     * drawn a while ago — then the line into the member's basket in this
     * shop, with the old quantity, and on to the basket page. Not straight to
     * the checkout: it buys the whole basket, and the basket shows what else
     * is in it.
     */
    private function renew(int $shopId, int $memberId, int $subscriptionId, string $language, string $here, int $now, RenewalContext|null $context): string
    {
        $subscription = $this->owned($shopId, $memberId, $subscriptionId);

        if (null === $subscription) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'notFound']);
        }

        $offer = $this->offer($subscription, $language, $now, $context);

        if (null === $offer || null === $context || null === $this->carts) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'renewalUnavailable']);
        }

        $cart = $this->carts->findForMember($memberId, $shopId);
        $cartId = null === $cart ? $this->carts->create(null, $memberId, $shopId, $now) : $cart->id;

        $this->carts->addRenewal($cartId, $offer->articleId, $offer->quantity, $offer->ruleId, $offer->subscriptionId, $now);

        return $context->cartUrl;
    }

    private function open(int $shopId, string|null $orderPage, int $memberId, int $subscriptionId, string $here, int $now): string
    {
        $subscription = $this->owned($shopId, $memberId, $subscriptionId);

        if (null === $subscription) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'notFound']);
        }

        if (null === $orderPage) {
            return $here.'?'.http_build_query([self::NOTICE_PARAM => 'noOrderPage']);
        }

        $token = $this->tokens->issue((int) $subscription['firstOrder'], TokenPurpose::ViewOrder, $now + MyOrdersFlow::OPEN_LIFETIME, false);

        return $orderPage.'?'.http_build_query([OrderController::TOKEN_PARAM => $token]);
    }

    /**
     * The earliest end of an ordinary cancellation now, for the confirmation
     * page (Abo-7) — '' where it has an end already or has ended.
     *
     * @param array<string, mixed> $subscription
     */
    private function earliest(array $subscription, string $language, int $now): string
    {
        if ('ended' === (string) $subscription['state'] || (int) ($subscription['endsAt'] ?? 0) > 0) {
            return '';
        }

        return CancellationForm::moment($this->term->ordinary($subscription, $now, null)->effectiveAt, $language);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private static function contract(array $subscription): string
    {
        $product = (string) ($subscription['productName'] ?? '');

        return '' === $product ? (string) $subscription['subscriptionNumber'] : $subscription['subscriptionNumber'].' — '.$product;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Digital\OrderPageDownloads;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherDocument;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDeliveryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderMailPayload;
use Webwerkwien\ContaoConshoBundle\Notification\PaymentChangeMail;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderPageRepository;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLinkOutcome;
use Webwerkwien\ContaoConshoBundle\Order\OrderLinkRequest;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChange;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeBlock;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeOptions;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRefused;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttemptRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStarter;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStartOutcome;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;
use Webwerkwien\ContaoConshoBundle\Token\TokenValidator;
use Webwerkwien\ContaoConshoBundle\Token\ValidToken;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Withdrawal\OrderPageWithdrawal;
/**
 * The order page (7.7, C21, C-10): the frozen order, its payment and delivery
 * state, the documents, and changing the payment method.
 *
 * ── Who gets in ───────────────────────────────────────────────────────────
 *
 * Whoever holds the link: the view token in `?consho_order=`, scoped to its
 * purpose (TokenValidator). There is no login: a guest has none, and the
 * token opens ONE order, not an account.
 *
 * An unknown, expired, malformed or missing token — and an order of another
 * shop — all get the same form "Neuen Link anfordern" (D-6, T4; until then a
 * 404). Tokens expire since D-6, so a dead link must lead somewhere. The page
 * still does not say whether an order exists (7.7): see OrderLinkRequest.
 *
 * ── Private, not indexed, no referrer ─────────────────────────────────────
 *
 * The token sits in the address, so the address must not leak: no referrer
 * to a linked site, no index, no shared cache. The headers go through
 * Contao's response context, because this element is a fragment and its own
 * response headers other than the cache directives do not reach the page.
 *
 * ── Actions are buttons, and the first one confirms the address ─────────
 *
 * Every action is a POST with Contao's request token (as in the basket). A
 * GET changes nothing: mail scanners follow links on their own, and a page
 * that recorded "the customer confirmed their address" on a GET would record
 * the scanner (7.7). The FIRST POST sets `emailConfirmedAt`, whatever it asks
 * for — through a token that came by mail only (C-10): the redirect after the
 * purchase carries one of its own, which opens the page and confirms nothing.
 *
 * The PDF and HTML of a document answer the POST directly — a document is a
 * response, not a state change a reload could repeat. A change of payment
 * method answers with a 303 back to the page, token included, and the
 * outcome in `?consho_notice=`: no session, like the basket (C-3c).
 *
 * ── The withdrawal function (D-2) ─────────────────────────────────────────
 *
 * "Vertrag widerrufen" → declaration → "Widerruf bestätigen", see
 * OrderPageWithdrawal. The declaration is a GET (`consho_withdraw=1`), the
 * confirmation a POST like every other action here.
 */
#[AsContentElement(type: 'consho_order', category: 'consho')]
class OrderController extends AbstractContentElementController
{
    public const TOKEN_PARAM = 'consho_order';

    public const NOTICE_PARAM = 'consho_notice';

    /** The outcome of "Neuen Link anfordern" (D-6, T4), an OrderLinkOutcome value. */
    public const LINK_PARAM = 'consho_link';

    private const NOTICES = [
        'changed', 'notOpen', 'statusLocks', 'alreadyChanged', 'methodUnavailable', 'confirmMissing', 'paymentFailed', 'afterWithdrawal', 'overdue',
        // D-2
        'withdrawn', 'withdrawnUnconfirmed', 'withdrawClosed', 'withdrawInvalid', 'withdrawNothingSelected', 'withdrawNameMissing',
    ];

    public function __construct(
        private readonly TokenValidator $tokens,
        private readonly DbalOrderPageRepository $orders,
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly OrderConfirmationSourceInterface $source,
        private readonly DocumentPayloadBuilder $payloads,
        private readonly InvoiceView $view,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly DocumentDeliveryInterface $documents,
        private readonly PaymentChange $paymentChange,
        private readonly PaymentChangeMail $mail,
        private readonly MoneyFormatter $money,
        private readonly ContentUrlGenerator $urls,
        private readonly ResponseContextAccessor $contexts,
        private readonly TokenGenerator $issuer,
        private readonly PaymentStarter $payments,
        private readonly PaymentAttemptRepositoryInterface $attempts,
        private readonly OrderPageWithdrawal $withdrawal,
        private readonly OrderLinkRequest $linkRequest,
        // Optional for the tests that build the controller by hand (F-5).
        private readonly OrderPageDownloads|null $downloads = null,
        // Block Wertgutscheine (WG14): the vouchers bought with this order,
        // with what is left on them. Optional like the downloads.
        private readonly VoucherDocument|null $vouchers = null,
        // Block GARAN (GA3, GA6): the labels frozen on the lines. Optional
        // like the above.
        private readonly DurabilityLabelImages|null $durability = null,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;

        if (!$page instanceof PageModel || null === $shop) {
            return new Response('');
        }

        $this->keepPrivate();

        $token = $request->query->getString(self::TOKEN_PARAM);
        $valid = $this->tokens->resolve($token, TokenPurpose::ViewOrder);
        $orderId = $valid?->orderId;
        $order = null === $orderId ? null : $this->orders->order($orderId);

        // One answer for every way of not getting in (7.7): the form for a
        // new link (D-6, T4).
        if (null === $valid || null === $orderId || null === $order || (int) $order['shop'] !== (int) $shop->id) {
            return $this->linkRequest($template, $page, $request, (int) $shop->id);
        }

        $fallback = $this->languages->fallbackOf((int) $shop->id);
        $language = '' !== (string) $page->language ? (string) $page->language : $fallback;

        if ($request->isMethod('POST') && '' !== $request->request->getString('consho_order_action')) {
            $this->act($request, $page, $valid, $token, $fallback);
        }

        /*
         * The link from the mail, opened for the first time (acceptance
         * C-10, Michael 2026-09-25): a single button "show order" before the
         * order. Mailbox scanners follow links but submit no forms, so the
         * click — a POST — is the reader, and it confirms the address. Just
         * reading without a click would otherwise leave every customer
         * unconfirmed. Not a captcha: that would test the same thing harder,
         * shut out screen readers, and stand in front of the withdrawal
         * button block D puts on this page (§ 13a FAGG: easily reachable).
         *
         * The first open is recorded either way (`usedAt`): it proves the
         * mail was DELIVERED, a scanner's visit included.
         */
        if ($valid->confirmsEmail) {
            $this->tokens->recordUse($token);

            if ('' === ($order['emailConfirmedAt'] ?? '')) {
                $template->set('gate', true);
                $template->set('order_number', $order['orderNumber']);

                // "Vertrag widerrufen" on the gate as well (decision E5): one
                // click less, and its POST confirms the address the same way.
                $template->set('withdrawal', $this->withdrawal->view($orderId, $order, false, $language, time()));

                $response = $template->getResponse();
                $response->setPrivate();
                $response->headers->addCacheControlDirective('no-store');

                return $response;
            }
        }

        $template->set('gate', false);

        $invoice = $this->source->invoice($orderId);
        $orderView = $this->view->build(['type' => 'invoice'], (new OrderMailPayload($this->source, $this->payloads))->of($order, $invoice));
        $balance = $this->balances->of($orderId) ?? throw new \LogicException(\sprintf('Order %d vanished while its page was built.', $orderId));
        $options = $this->paymentChange->options($orderId, $fallback);

        $template->set('order', $orderView);
        $template->set('garan', $this->durability?->ofOrderItems($this->source->items($orderId)) ?? []);
        $template->set('reference', null !== $invoice && [] !== $orderView['bankDetails'] ? $invoice['documentNumber'] : '');
        $paymentState = $balance->paymentState;
        $template->set('payment_state', $paymentState->value);
        $template->set('online', $this->onlineOf($orderId, $paymentState));
        $template->set('delivery_state', $balance->deliveryState->value);
        $template->set('documents', $this->documentsOf($orderId, $language));
        $template->set('entitlements', $this->entitlementsOf($orderId, $language));
        $template->set('vouchers', null === $this->vouchers ? [] : $this->vouchers->ofOrder($orderId, $language));
        $this->paymentsOf($template, $orderId, (string) ($order['currency'] ?? ''), $language, $balance->outstanding());
        $template->set('change', $this->changeOf($options, $order['currency'], $language));
        $template->set('notice', self::noticeOf($request));
        $template->set('withdrawal', $this->withdrawal->view($orderId, $order, '1' === $request->query->getString(OrderPageWithdrawal::FORM_PARAM), $language, time()));
        // For the GET form of "Vertrag widerrufen" — the same token the page
        // was opened with, which the address already carries.
        $template->set('withdrawal_token', $token);
        $template->set('order_url', $this->addressOf($page, $request, $token));

        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @internal public for the test only
     */
    public static function noticeOf(Request $request): string|null
    {
        $notice = $request->query->getString(self::NOTICE_PARAM);

        if (\in_array($notice, self::NOTICES, true)) {
            return $notice;
        }

        // A module's notice after its action (LZ-1, E2): only a key its
        // language file brings — the address is no place for free text.
        return 1 === preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $notice) && \is_string($GLOBALS['TL_LANG']['CONSHO']['orderPage']['notice'][$notice] ?? null) ? $notice : null;
    }

    private function act(Request $request, PageModel $page, ValidToken $valid, string $token, string $fallback): never
    {
        $now = time();
        $orderId = $valid->orderId;

        /*
         * Whatever the button, it was a person (7.7) — before anything else,
         * so that a document delivered below has already counted.
         *
         * But only through a token that came BY MAIL (C-10). The redirect
         * after the purchase opens this page with a token of its own, and a
         * click there proves nothing about the address.
         */
        if ($valid->confirmsEmail) {
            $this->orders->confirmEmail($orderId, $now);
        }

        $action = $request->request->getString('consho_order_action');

        if ('pdf' === $action || 'html' === $action) {
            $this->deliver($action, $orderId, (int) $request->request->getString('document'));
        }

        // A file of an entitlement of THIS order (F-5, F18); anything else is
        // a 404, like a document of somebody else's order.
        if ('download' === $action) {
            throw new ResponseException($this->downloads?->download($orderId, (int) $request->request->getString('entitlement'), $now, $request->request->getString('item')) ?? throw new PageNotFoundException('Download not found.'));
        }

        // A module's button on its delivered item (block licence, LZ-1, E2):
        // back to the page with the module's notice; anything not offered
        // there is a 404.
        if ('delivery' === $action) {
            $notice = $this->downloads?->act($orderId, (int) $request->request->getString('entitlement'), $request->request->getString('item'), $request->request->getString('delivery_action'), $now)
                ?? throw new PageNotFoundException('Action not found.');

            throw new RedirectResponseException($this->addressOf($page, $request, $token, $notice));
        }

        // "Vertrag widerrufen" on the gate (E5): the address is confirmed
        // above, on to the declaration.
        if ('withdrawStart' === $action) {
            throw new RedirectResponseException($this->addressOf($page, $request, $token, null, true));
        }

        // "Widerruf bestätigen" (D-2). Back to the declaration where the input
        // can be corrected, to the order where it was recorded or refused.
        if ('withdraw' === $action) {
            $declared = $this->withdrawal->declare($orderId, $request->request, $now);

            throw new RedirectResponseException($this->addressOf($page, $request, $token, $declared['notice'], $declared['form']));
        }

        /*
         * "Try again" (7.4, C-11): a new attempt of the same method. Checked
         * again here, not trusted from the rendered page — the notification
         * may have paid the order since, or another tab started an attempt.
         */
        if ('retry' === $action) {
            if (!$this->payments->canRetry($orderId, $now)) {
                throw new RedirectResponseException($this->addressOf($page, $request, $token));
            }

            $this->afterStart($this->payments->start($orderId, $now), $page, $request, $token, null);
        }

        if ('change' !== $action) {
            throw new RedirectResponseException($this->addressOf($page, $request, $token));
        }

        if ('' === $request->request->getString('consho_confirm')) {
            throw new RedirectResponseException($this->addressOf($page, $request, $token, 'confirmMissing'));
        }

        try {
            $changed = $this->paymentChange->change($orderId, (int) $request->request->getString('method'), $fallback, $now);
        } catch (PaymentChangeRefused $e) {
            throw new RedirectResponseException($this->addressOf($page, $request, $token, $e->block->value));
        }

        // After the commit, never throwing: the change stands whatever the
        // mail server says, and the page shows the documents either way.
        //
        // A token of its own for the link in that mail, and one that
        // confirms: the page may have been opened through the redirect's.
        $mailToken = $this->issuer->issue($orderId, TokenPurpose::ViewOrder, null, true);
        $this->mail->send($orderId, $changed->cancellationId, $changed->invoiceId, $this->addressOf($page, $request, $mailToken));

        // start() of the new method (7.5 step 3, C-11): off to the provider
        // where it has one.
        $this->afterStart($this->payments->start($orderId, $now), $page, $request, $token, 'changed');
    }

    /**
     * Where the buyer goes once a payment has been started: to the provider
     * for a redirect, back to this page otherwise — with the failure said
     * out loud, so "try again" is right there.
     */
    private function afterStart(PaymentStart|null $start, PageModel $page, Request $request, string $token, string|null $notice): never
    {
        if (PaymentStartOutcome::Redirect === $start?->outcome) {
            throw new RedirectResponseException($start->url, Response::HTTP_SEE_OTHER);
        }

        if (PaymentStartOutcome::Failed === $start?->outcome) {
            $notice = 'paymentFailed';
        }

        throw new RedirectResponseException($this->addressOf($page, $request, $token, $notice));
    }

    /**
     * The payments and what is still open (acceptance WG-7: a customer who
     * paid part with a voucher read only "partly paid"). Labelled like the
     * invoice; the open amount only where something was paid and something
     * is left — a fresh unpaid order says it in its payment block.
     */
    private function paymentsOf(FragmentTemplate $template, int $orderId, string $currency, string $language, string $outstanding): void
    {
        $payments = $this->orders->payments($orderId);

        $template->set('payments', array_map(fn (array $payment): array => [
            'label' => 'refund' === $payment['method'] ? '' : $payment['method'],
            'refund' => 'refund' === $payment['method'] || \Brick\Math\BigDecimal::of($payment['amount'])->isNegative(),
            'amount' => $this->money->format($payment['amount'], $currency, $language),
        ], $payments));
        $template->set('outstanding', [] !== $payments && \Brick\Math\BigDecimal::of($outstanding)->isPositive() ? $this->money->format($outstanding, $currency, $language) : '');
    }

    /**
     * The sentence about the online payment (C-11), or null where there is
     * nothing to say: no attempt (orders before C-11), or an attempt that
     * started complete — prepayment, whose block is the payment notice.
     *
     * Only what is proven (Michael, 2026-09-26): `confirmed` needs the booked
     * notification, `reported` a return the handler VERIFIED; everything
     * else open is `none`.
     *
     * @return array{state: string, retry: bool}|null
     */
    private function onlineOf(int $orderId, PaymentState $paymentState): array|null
    {
        $latest = $this->attempts->latestFor($orderId);
        $order = $this->orders->order($orderId);

        // An attempt of ANOTHER method is the one a change of method cancelled
        // (review C-11, finding 4): if the new method could not start, saying
        // "the payment was cancelled" would be about the old one.
        if (null === $latest || null === $order || $latest->methodId !== (int) ($order['paymentMethod'] ?? 0)) {
            return null;
        }

        if (PaymentState::Open !== $paymentState) {
            return AttemptState::Succeeded === $latest->state ? ['state' => 'confirmed', 'retry' => false] : null;
        }

        $state = match (true) {
            AttemptState::Failed === $latest->state => 'failed',
            AttemptState::Cancelled === $latest->state => 'cancelled',
            AttemptState::Open === $latest->state && AttemptState::Succeeded === $latest->returned => 'reported',
            AttemptState::Open === $latest->state => 'none',
            default => null,
        };

        if (null === $state) {
            return null;
        }

        // "none" too: an open attempt that has lapsed (PaymentStarter::lapsed)
        // — the buyer left the provider's page, and a click must bring them
        // back rather than to "contact the shop".
        return ['state' => $state, 'retry' => \in_array($state, ['failed', 'cancelled', 'none'], true) && $this->payments->canRetry($orderId, time())];
    }

    /**
     * The entitlements for the template (F-5): the end date formatted in the
     * page's language, like the documents' dates.
     *
     * @return list<array{id: int, name: string, quantity: string, until: string, active: bool, resting: bool, file: bool, keys: list<string>, items: list<array{id: string, label: string, text: string|null, download: bool, action: string|null, actionLabel: string|null}>, service: bool, subscription: bool, performed: string}>
     */
    private function entitlementsOf(int $orderId, string $language): array
    {
        if (null === $this->downloads) {
            return [];
        }

        $formatter = new \IntlDateFormatter($language, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get());

        return array_map(
            fn (array $row): array => [
                'id' => $row['id'],
                'name' => $row['name'],
                'quantity' => $this->money->quantity($row['quantity'], $language),
                'until' => null === $row['validUntil'] ? '' : (string) $formatter->format($row['validUntil']),
                'active' => $row['active'],
                'resting' => $row['resting'],
                'file' => $row['file'],
                'keys' => $row['keys'],
                'items' => $row['items'],
                'service' => $row['service'],
                'subscription' => $row['subscription'],
                'performed' => null === $row['performedAt'] ? '' : (string) $formatter->format($row['performedAt']),
            ],
            $this->downloads->view($orderId, time()),
        );
    }

    /**
     * A document of THIS order, as PDF or HTML. The id comes from the form,
     * so it is checked against the order's own list — a guessed number of
     * somebody else's invoice is a 404, like a wrong token.
     */
    private function deliver(string $format, int $orderId, int $documentId): never
    {
        $ids = array_column($this->orders->documents($orderId), 'id');

        if (!\in_array($documentId, $ids, true)) {
            throw new PageNotFoundException('Document not found.');
        }

        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];

        if ('pdf' === $format) {
            $pdf = $this->documents->pdf($documentId) ?? throw new PageNotFoundException('Document not found.');

            throw new ResponseException(new Response($pdf['pdf'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $pdf['fileName']),
            ] + $headers));
        }

        $html = $this->documents->html($documentId) ?? throw new PageNotFoundException('Document not found.');

        throw new ResponseException(new Response($html['html'], 200, ['Content-Type' => 'text/html; charset=utf-8'] + $headers));
    }

    /**
     * @return list<array{id: int, type: string, number: string, date: string, cancelled: bool}>
     */
    private function documentsOf(int $orderId, string $language): array
    {
        $formatter = new \IntlDateFormatter($language, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get());

        return array_map(
            static fn (array $document): array => [
                'id' => $document['id'],
                'type' => $document['type'],
                'number' => $document['number'],
                'date' => $document['issuedAt'] > 0 ? (string) $formatter->format($document['issuedAt']) : '',
                'cancelled' => $document['cancelled'],
            ],
            $this->orders->documents($orderId),
        );
    }

    /**
     * @return array{offered: bool, block: string|null, options: list<array{id: int, title: string, available: bool, current: bool, amount: string}>}
     */
    private function changeOf(PaymentChangeOptions $options, string $currency, string $language): array
    {
        $list = [];

        foreach ($options->options as $option) {
            $list[] = [
                'id' => $option->method->id,
                'title' => $option->method->title,
                'available' => $option->available,
                'current' => $option->current,
                'amount' => '0' !== $option->gross && '' !== $option->gross ? $this->money->format($option->gross, $currency, $language) : '',
            ];
        }

        return [
            'offered' => $options->offersChange(),
            // Only the blocks that describe the ORDER are worth a sentence;
            // "method unavailable" belongs to a request.
            'block' => null !== $options->block && PaymentChangeBlock::MethodUnavailable !== $options->block ? $options->block->value : null,
            'options' => $list,
        ];
    }

    /**
     * "Neuen Link anfordern" (D-6, T4) — the page for every way of not
     * getting in. The POST answers 303 back to the page WITHOUT the token and
     * with the outcome, so a reload sends nothing twice.
     *
     * Also the answer to an ACTION whose token expired while the page was
     * open (a redirect token lives a day): the action is not carried out,
     * and the form appears in its place (Fable review D-6, K5).
     */
    private function linkRequest(FragmentTemplate $template, PageModel $page, Request $request, int $shopId): Response
    {
        if ($request->isMethod('POST') && 'requestLink' === $request->request->getString('consho_order_action')) {
            $outcome = $this->linkRequest->request(
                $shopId,
                $request->request->getString('consho_link_number'),
                $request->request->getString('consho_link_email'),
                $request->getClientIp() ?? 'unknown',
            );

            throw new RedirectResponseException($this->pageUrl($page, $request, [self::LINK_PARAM => $outcome->value]), Response::HTTP_SEE_OTHER);
        }

        $template->set('link_request', true);
        $template->set('link_outcome', OrderLinkOutcome::tryFrom($request->query->getString(self::LINK_PARAM))?->value);

        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function keepPrivate(): void
    {
        $context = $this->contexts->getResponseContext();

        if (null === $context) {
            return;
        }

        $context->getHeaderBag()->set('Referrer-Policy', 'no-referrer');
        $context->getHeaderBag()->set('X-Robots-Tag', 'noindex, nofollow');

        if ($context->has(HtmlHeadBag::class)) {
            $head = $context->get(HtmlHeadBag::class);
            $head->setMetaRobots('noindex,nofollow');

            /*
             * The policy in the DOCUMENT as well (acceptance on c5,
             * 2026-09-25): the web server there sets its own Referrer-Policy
             * header and overrides the one above. A meta referrer applies to
             * the document whatever the server says, so the token reaches
             * neither another site nor the shop's own logs and statistics
             * (Michael: „wenn 2 Vorteile hat, dann ja").
             */
            $head->removeMetaTag('name', 'referrer');
            $head->addMetaTag(new HtmlAttributes(['name' => 'referrer', 'content' => 'no-referrer']));
        }
    }

    private function addressOf(PageModel $page, Request $request, string $token, string|null $notice = null, bool $withdrawForm = false): string
    {
        $query = [self::TOKEN_PARAM => $token]
            + (null === $notice ? [] : [self::NOTICE_PARAM => $notice])
            + ($withdrawForm ? [OrderPageWithdrawal::FORM_PARAM => '1'] : []);

        return $this->pageUrl($page, $request, $query);
    }

    /**
     * @param array<string, string> $query
     */
    private function pageUrl(PageModel $page, Request $request, array $query): string
    {
        try {
            return $this->urls->generate($page, $query, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (ExceptionInterface) {
            return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query);
        }
    }
}

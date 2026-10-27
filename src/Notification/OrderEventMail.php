<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Webwerkwien\ContaoConshoBundle\Mail\SenderAddress;
use Symfony\Component\Mime\Email;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherDocument;

/**
 * The mails after a payment and after a delivery (D-1, decisions Z3 and Z4).
 *
 *  - "Zahlung erhalten" once the payment balance is settled, with the invoice
 *    attached if the payment just issued one. A copy to the operator only for
 *    a payment that arrived online.
 *  - "Versendet" for every reported delivery, with what is in this parcel,
 *    the tracking number and the invoice if the delivery just issued one. No
 *    copy: the operator reported it.
 *  - The confirmation of receipt of a withdrawal (D-2), with the declaration
 *    and the date AND time of receipt. Always with a copy: the operator has
 *    to act on it.
 *
 * Like PaymentChangeMail it never throws — the event has committed, and a
 * mail server that is down is no reason to undo it. The failure is logged.
 *
 * Not final: the event services' tests stand a double in for it.
 */
class OrderEventMail
{
    public const PAYMENT_TEMPLATE = '@Contao/mail/consho_payment_received.html.twig';
    public const PAYMENT_OPERATOR_TEMPLATE = '@Contao/mail/consho_payment_received_operator.html.twig';
    public const SHIPPED_TEMPLATE = '@Contao/mail/consho_order_shipped.html.twig';
    public const WITHDRAWAL_TEMPLATE = '@Contao/mail/consho_withdrawal_received.html.twig';
    public const WITHDRAWAL_OPERATOR_TEMPLATE = '@Contao/mail/consho_withdrawal_received_operator.html.twig';
    public const WITHDRAWAL_UNASSIGNED_TEMPLATE = '@Contao/mail/consho_withdrawal_received_unassigned.html.twig';
    public const WITHDRAWAL_UNASSIGNED_OPERATOR_TEMPLATE = '@Contao/mail/consho_withdrawal_received_unassigned_operator.html.twig';
    public const WITHDRAWAL_REJECTED_TEMPLATE = '@Contao/mail/consho_withdrawal_rejected.html.twig';
    public const REFUND_TEMPLATE = '@Contao/mail/consho_refund_booked.html.twig';
    public const DIGITAL_RELEASED_TEMPLATE = '@Contao/mail/consho_digital_released.html.twig';
    public const VOUCHERS_ISSUED_TEMPLATE = '@Contao/mail/consho_vouchers_issued.html.twig';
    public const SUBSCRIPTION_INVOICE_TEMPLATE = '@Contao/mail/consho_subscription_invoice.html.twig';
    public const SUBSCRIPTION_INVOICE_OPERATOR_TEMPLATE = '@Contao/mail/consho_subscription_invoice_operator.html.twig';
    public const SUBSCRIPTION_SUSPENDED_TEMPLATE = '@Contao/mail/consho_subscription_suspended.html.twig';
    public const SUBSCRIPTION_SUSPENDED_OPERATOR_TEMPLATE = '@Contao/mail/consho_subscription_suspended_operator.html.twig';
    public const SUBSCRIPTION_RESUMED_TEMPLATE = '@Contao/mail/consho_subscription_resumed.html.twig';
    public const PURCHASE_SUSPENDED_TEMPLATE = '@Contao/mail/consho_purchase_suspended.html.twig';
    public const PURCHASE_RESUMED_TEMPLATE = '@Contao/mail/consho_purchase_resumed.html.twig';
    public const SUBSCRIPTION_CHARGE_PENDING_TEMPLATE = '@Contao/mail/consho_subscription_charge_pending.html.twig';
    public const SUBSCRIPTION_PAUSED_TEMPLATE = '@Contao/mail/consho_subscription_paused.html.twig';
    public const SUBSCRIPTION_PAUSED_OPERATOR_TEMPLATE = '@Contao/mail/consho_subscription_paused_operator.html.twig';
    public const SUBSCRIPTION_REMINDER_TEMPLATE = '@Contao/mail/consho_subscription_reminder.html.twig';
    public const CANCELLATION_TEMPLATE = '@Contao/mail/consho_cancellation_received.html.twig';
    public const CANCELLATION_OPERATOR_TEMPLATE = '@Contao/mail/consho_cancellation_received_operator.html.twig';
    public const CANCELLATION_DECIDED_TEMPLATE = '@Contao/mail/consho_cancellation_decided.html.twig';
    public const DOCUMENT_ISSUED_TEMPLATE = '@Contao/mail/consho_document_issued.html.twig';

    public function __construct(
        private readonly OrderConfirmationSourceInterface $source,
        private readonly MailRenderer $renderer,
        private readonly RecipientResolver $recipients,
        private readonly MailTransportFactoryInterface $transports,
        private readonly DocumentPayloadBuilder $payloads,
        private readonly InvoiceView $view,
        private readonly DocumentPdfInterface $pdfs,
        // Block Wertgutscheine (WG-4): codes and PDFs of the vouchers issued.
        private readonly VoucherDocument $vouchers,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly MoneyFormatter $money = new MoneyFormatter(),
        // GARAN part 2 (L3): logo and sender in the customer's mails. Optional for the tests.
        private readonly MailFrames|null $frames = null,
    ) {
    }

    /**
     * @return bool whether the customer's mail left
     */
    public function paymentReceived(int $orderId, int|null $invoiceId, string $viewUrl, bool $copyOperator): bool
    {
        try {
            [$order, $shop, $transport, $from, $payload, $invoice, $attachments] = $this->prepare($orderId, $invoiceId);
        } catch (\Throwable $e) {
            $this->fail('payment', '', $orderId, $e);

            return false;
        }

        $number = (string) ($invoice['documentNumber'] ?? '');

        $sent = $this->toCustomer('payment', $order, self::PAYMENT_TEMPLATE, $payload, [
            'invoice' => $number,
            'viewUrl' => $viewUrl,
            // The payment released digital content (F-7, F20): said here,
            // not in a mail of its own. Which sentence by what it released
            // (DL3): '' for nothing, else digital, service or mixed.
            'digitalReady' => self::released($this->source->entitlements($orderId, (int) ($order['paymentSettledAt'] ?? 0))),
        ], $attachments, $transport, $from);

        if ($copyOperator) {
            $this->toOperator('payment', $order, $shop, self::PAYMENT_OPERATOR_TEMPLATE, $payload, ['invoice' => $number], $attachments, $transport, $from);
        }

        return $sent;
    }

    /**
     * "Ihre digitalen Inhalte sind bereit" (F-7, F20): after a release that
     * no other mail announces — the end of the withdrawal period, a refilled
     * key pool. The quantities formatted in the order's language.
     *
     * @return bool whether the customer's mail left
     */
    public function digitalReleased(int $orderId, int $since, string $viewUrl, int|null $invoiceId = null): bool
    {
        try {
            [$order, , $transport, $from, $payload, $invoice, $attachments] = $this->prepare($orderId, $invoiceId);
        } catch (\Throwable $e) {
            $this->fail('digitalReleased', '', $orderId, $e);

            return false;
        }

        $language = (string) ($order['language'] ?? '');
        $released = $this->source->entitlements($orderId, $since);
        $items = array_map(
            fn (array $item): array => ['quantity' => $this->money->quantity($item['quantity'], $language), 'name' => $item['name']],
            $released,
        );

        return $this->toCustomer('digitalReleased', $order, self::DIGITAL_RELEASED_TEMPLATE, $payload, [
            'items' => $items,
            'released' => self::released($released),
            'viewUrl' => $viewUrl,
            // The invoice this release issued (DL): attached.
            'invoice' => (string) ($invoice['documentNumber'] ?? ''),
        ], $attachments, $transport, $from);
    }

    /**
     * "Ihr Gutschein" (block Wertgutscheine, WG4): the codes the release
     * issued, and a PDF of each to print or pass on — the mail is how they
     * reach the buyer. With the invoice the release issued, if no other mail
     * of this release carries it.
     *
     * @param list<int> $voucherIds
     */
    public function vouchersIssued(int $orderId, array $voucherIds, string $viewUrl, int|null $invoiceId = null): bool
    {
        try {
            [$order, , $transport, $from, $payload, $invoice, $attachments] = $this->prepare($orderId, $invoiceId);
            $language = (string) ($order['language'] ?? '');
            $vouchers = $this->vouchers->view($voucherIds, $language);
            $attachments = [...$this->vouchers->pdfs($voucherIds, $language), ...$attachments];
        } catch (\Throwable $e) {
            $this->fail('vouchersIssued', '', $orderId, $e);

            return false;
        }

        return $this->toCustomer('vouchersIssued', $order, self::VOUCHERS_ISSUED_TEMPLATE, $payload, [
            'vouchers' => $vouchers,
            'viewUrl' => $viewUrl,
            'invoice' => (string) ($invoice['documentNumber'] ?? ''),
        ], $attachments, $transport, $from);
    }

    /**
     * What a release unlocked, for the sentence that says so (DL3): ''
     * nothing, `digital` content only, `service` services only, `mixed`.
     *
     * @param list<array{service: bool}> $entitlements
     */
    public static function released(array $entitlements): string
    {
        if ([] === $entitlements) {
            return '';
        }

        $services = \count(array_filter($entitlements, static fn (array $e): bool => $e['service']));

        return match ($services) {
            0 => 'digital',
            \count($entitlements) => 'service',
            default => 'mixed',
        };
    }

    /**
     * The invoice of a subscription's follow-up order (Abo-4, P30), with the
     * period it covers, and the operator's copy.
     *
     * Deliberately NOT the order confirmation: that one carries the
     * withdrawal instructions and the consent sentence, and a follow-up order
     * opens no withdrawal period (A12). The dates in each recipient's
     * language, without the time — a period is read in days.
     *
     * Two versions (Abo-4b, P97): "on account", and charged with the
     * subscription's mandate — then it says on which day and through which
     * method the amount was charged.
     *
     * @param int $chargedAt when the amount was charged; 0: "on account"
     *
     * @return bool whether the customer's mail left
     */
    public function subscriptionInvoice(int $orderId, string $subscriptionNumber, int $periodFrom, int $periodUntil, string $viewUrl, int $chargedAt = 0): bool
    {
        try {
            $invoiceId = $this->source->invoice($orderId)['id'] ?? null;
            [$order, $shop, $transport, $from, $payload, $invoice, $attachments] = $this->prepare($orderId, null === $invoiceId ? null : (int) $invoiceId);
        } catch (\Throwable $e) {
            $this->fail('subscriptionInvoice', '', $orderId, $e);

            return false;
        }

        $for = static fn (string $language): array => [
            'subscription' => $subscriptionNumber,
            'periodFrom' => self::day($periodFrom, $language),
            'periodUntil' => self::day($periodUntil, $language),
            'invoice' => (string) ($invoice['documentNumber'] ?? ''),
            'charged' => $chargedAt > 0 ? self::day($chargedAt, $language) : '',
            'paymentMethod' => (string) ($order['paymentMethodTitle'] ?? ''),
        ];

        $sent = $this->toCustomer('subscriptionInvoice', $order, self::SUBSCRIPTION_INVOICE_TEMPLATE, $payload, [
            ...$for((string) ($order['language'] ?? '')),
            'viewUrl' => $viewUrl,
        ], $attachments, $transport, $from);

        $this->toOperator('subscriptionInvoice', $order, $shop, self::SUBSCRIPTION_INVOICE_OPERATOR_TEMPLATE, $payload, $for($shop['fallbackLanguage']), $attachments, $transport, $from);

        return $sent;
    }

    /**
     * "Ihr Abo ruht" (Abo-5, P37): an invoice of the subscription is past its
     * term and grace. The open invoice attached, what is open and since when,
     * the order page for the payment details; a copy to the operator. It
     * neither threatens nor counts reminders — dunning is not the core's (A6).
     *
     * @param int    $orderId     the (oldest) overdue order
     * @param string $outstanding what it still owes, unformatted
     *
     * @return bool whether the customer's mail left
     */
    public function subscriptionSuspended(int $orderId, string $subscriptionNumber, string $outstanding, string $viewUrl): bool
    {
        try {
            $invoiceId = $this->source->invoice($orderId)['id'] ?? null;
            [$order, $shop, $transport, $from, $payload, $invoice, $attachments] = $this->prepare($orderId, null === $invoiceId ? null : (int) $invoiceId);
        } catch (\Throwable $e) {
            $this->fail('subscriptionSuspended', '', $orderId, $e);

            return false;
        }

        $for = fn (string $language): array => [
            'subscription' => $subscriptionNumber,
            'invoice' => (string) ($invoice['documentNumber'] ?? ''),
            'dueDate' => self::day((int) ($order['paymentDueAt'] ?? 0), $language),
            'outstanding' => $this->money->format($outstanding, (string) ($order['currency'] ?? ''), $language),
        ];

        $sent = $this->toCustomer('subscriptionSuspended', $order, self::SUBSCRIPTION_SUSPENDED_TEMPLATE, $payload, [
            ...$for((string) ($order['language'] ?? '')),
            'viewUrl' => $viewUrl,
        ], $attachments, $transport, $from);

        $this->toOperator('subscriptionSuspended', $order, $shop, self::SUBSCRIPTION_SUSPENDED_OPERATOR_TEMPLATE, $payload, $for($shop['fallbackLanguage']), [], $transport, $from);

        return $sent;
    }

    /**
     * "Ihr Abo ist wieder aktiv" (Abo-5, P37), to the customer only — the
     * operator recorded the payment or cancelled the order himself.
     *
     * @param int $orderId an order of the subscription, for address and language
     *
     * @return bool whether the customer's mail left
     */
    public function subscriptionResumed(int $orderId, string $subscriptionNumber, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('subscriptionResumed', '', $orderId, $e);

            return false;
        }

        return $this->toCustomer('subscriptionResumed', $order, self::SUBSCRIPTION_RESUMED_TEMPLATE, $payload, [
            'subscription' => $subscriptionNumber,
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);
    }

    /**
     * "Ihre digitalen Inhalte ruhen" (Nachlese E5): a one-time purchase on account is past its term
     * and grace (PurchaseDelinquency). Built like subscriptionSuspended(): the
     * open invoice attached, what is open and since when, the order page for
     * the payment details. To the customer only — the operator sees the
     * overdue invoice under "Hinweise".
     *
     * @param string $outstanding what the order still owes, unformatted
     *
     * @return bool whether the mail left
     */
    public function purchaseSuspended(int $orderId, string $outstanding, string $viewUrl): bool
    {
        try {
            $invoiceId = $this->source->invoice($orderId)['id'] ?? null;
            [$order, , $transport, $from, $payload, $invoice, $attachments] = $this->prepare($orderId, null === $invoiceId ? null : (int) $invoiceId);
        } catch (\Throwable $e) {
            $this->fail('purchaseSuspended', '', $orderId, $e);

            return false;
        }

        $language = (string) ($order['language'] ?? '');

        return $this->toCustomer('purchaseSuspended', $order, self::PURCHASE_SUSPENDED_TEMPLATE, $payload, [
            'invoice' => (string) ($invoice['documentNumber'] ?? ''),
            'dueDate' => self::day((int) ($order['paymentDueAt'] ?? 0), $language),
            'outstanding' => $this->money->format($outstanding, (string) ($order['currency'] ?? ''), $language),
            'viewUrl' => $viewUrl,
        ], $attachments, $transport, $from);
    }

    /**
     * "Ihre digitalen Inhalte stehen wieder zur Verfügung" (Nachlese E5), to
     * the customer only, once nothing of the purchase is overdue any more.
     *
     * @return bool whether the mail left
     */
    public function purchaseResumed(int $orderId, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('purchaseResumed', '', $orderId, $e);

            return false;
        }

        return $this->toCustomer('purchaseResumed', $order, self::PURCHASE_RESUMED_TEMPLATE, $payload, [
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);
    }

    /**
     * A document an invoicing module issued late (block R, R18): its module
     * failed or was missing when the order's mail went out, and
     * `consho:document:verify --retry` had it issued afterwards. Sent only
     * where Consho sends the module's documents — the attachment is the mail.
     *
     * @return bool whether the customer's mail left
     */
    public function documentIssued(int $orderId, int $documentId, DocumentType $type, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload, $document, $attachments] = $this->prepare($orderId, $documentId);
        } catch (\Throwable $e) {
            $this->fail('documentIssued', '', $orderId, $e);

            return false;
        }

        if (null === $document || [] === $attachments) {
            return false;
        }

        return $this->toCustomer('documentIssued', $order, self::DOCUMENT_ISSUED_TEMPLATE, $payload, [
            'document' => ['type' => $type->value, 'number' => (string) $document['documentNumber']],
            'viewUrl' => $viewUrl,
        ], $attachments, $transport, $from);
    }

    /**
     * "Abbuchung gestartet" (E7): a charge with the subscription's mandate
     * that the provider reports as pending. The amount, the period and the
     * method, and that the invoice follows with the payment. To the customer
     * only — the operator sees the pending attempt at the order.
     *
     * Whether an advance notice of a direct debit is required (SEPA
     * pre-notification) is NOT checked here (spec Abo): this is information
     * after the start, not that notice.
     *
     * @return bool whether the customer's mail left
     */
    public function subscriptionChargePending(int $orderId, string $subscriptionNumber, int $periodFrom, int $periodUntil, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('subscriptionChargePending', '', $orderId, $e);

            return false;
        }

        $language = (string) ($order['language'] ?? '');

        return $this->toCustomer('subscriptionChargePending', $order, self::SUBSCRIPTION_CHARGE_PENDING_TEMPLATE, $payload, [
            'subscription' => $subscriptionNumber,
            'periodFrom' => self::day($periodFrom, $language),
            'periodUntil' => self::day($periodUntil, $language),
            'amount' => $this->money->format((string) ($order['grandTotal'] ?? '0'), (string) ($order['currency'] ?? ''), $language),
            'paymentMethod' => (string) ($order['paymentMethodTitle'] ?? ''),
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);
    }

    /**
     * "Pause bestätigt" (Abo pausieren, P110): from when to when, that nothing
     * is billed and the access rests meanwhile, and that the term moves by the
     * pause. A copy to the operator. The days in each recipient's language.
     *
     * @param int $orderId an order of the subscription, for address and language
     *
     * @return bool whether the customer's mail left
     */
    public function subscriptionPaused(int $orderId, string $subscriptionNumber, int $pauseFrom, int $pauseUntil, string $viewUrl): bool
    {
        try {
            [$order, $shop, $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('subscriptionPaused', '', $orderId, $e);

            return false;
        }

        $for = static fn (string $language): array => [
            'subscription' => $subscriptionNumber,
            'pauseFrom' => self::day($pauseFrom, $language),
            'pauseUntil' => self::day($pauseUntil, $language),
        ];

        $sent = $this->toCustomer('subscriptionPaused', $order, self::SUBSCRIPTION_PAUSED_TEMPLATE, $payload, [
            ...$for((string) ($order['language'] ?? '')),
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);

        $this->toOperator('subscriptionPaused', $order, $shop, self::SUBSCRIPTION_PAUSED_OPERATOR_TEMPLATE, $payload, $for($shop['fallbackLanguage']), [], $transport, $from);

        return $sent;
    }

    /**
     * The reminder before the end of a minimum term (Abo-6, P42, P43), to the
     * customer only. What follows the term, in the customer's language:
     *
     *  - ends: it ends; the product page to buy again
     *  - sameTerm: it renews by one term on the terms so far; cancel until …
     *  - indefinite: it runs on open-ended on the terms so far
     *  - endsNoMonthly: an open-ended rule from before variant A billed
     *    otherwise than monthly — it ends
     *
     * @param int                                                                                                                                                                                                  $orderId  an order of the subscription, for address and language
     * @param array{subscription: string, kind: string, product: string, termEndsAt: int, cancelUntil: int, termLength: int, termUnit: string, noticeDays: int, productUrl: string|null, renewUrl?: string|null} $reminder
     *
     * @return bool whether the customer's mail left
     */
    public function subscriptionReminder(int $orderId, array $reminder, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('subscriptionReminder', '', $orderId, $e);

            return false;
        }

        $language = (string) ($order['language'] ?? '');

        return $this->toCustomer('subscriptionReminder', $order, self::SUBSCRIPTION_REMINDER_TEMPLATE, $payload, [
            'subscription' => $reminder['subscription'],
            'kind' => $reminder['kind'],
            'product' => $reminder['product'],
            'termEnd' => self::day($reminder['termEndsAt'], $language),
            'cancelUntil' => self::day($reminder['cancelUntil'], $language),
            'durationKey' => 'year' === $reminder['termUnit'] ? (1 === $reminder['termLength'] ? 'year' : 'years') : (1 === $reminder['termLength'] ? 'month' : 'months'),
            'termLength' => (string) $reminder['termLength'],
            'noticeKey' => 1 === $reminder['noticeDays'] ? 'day' : 'days',
            'noticeDays' => (string) $reminder['noticeDays'],
            'productUrl' => $reminder['productUrl'] ?? '',
            'renewUrl' => $reminder['renewUrl'] ?? '',
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);
    }

    /**
     * The confirmation of receipt of a cancellation (Abo-7, P62; § 312k Abs. 4
     * BGB: the content of the declaration, the date and time of receipt and
     * when the contract ends by it), and the operator's copy. Formatted per
     * recipient, like withdrawalReceived().
     *
     * An extraordinary cancellation has no end yet: the mail says that the
     * operator decides and writes again (P57).
     *
     * @param int                                                                                                                                                                                                  $orderId      the latest order of the subscription, for address and language
     * Ends it later than the earliest possible end (a later date asked for),
     * the mail says that periods beginning before the end are still billed
     * in full (P55; Abo-7).
     *
     * @param array{number: string, subscription: string, product: string, receivedAt: int, kind: string, reason: string, name: string, email: string, requestedAt: int|null, effectiveAt: int|null, earliestApplied: bool, earliestAt: int|null} $cancellation
     *
     * @return bool whether the customer's mail left
     */
    public function cancellationReceived(int $orderId, array $cancellation, string $viewUrl): bool
    {
        try {
            [$order, $shop, $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('cancellation', '', $orderId, $e);

            return false;
        }

        $for = static fn (string $language): array => [
            ...$cancellation,
            'receivedAt' => self::moment($cancellation['receivedAt'], $language),
            // A day: the form asks for one.
            'requestedAt' => null === $cancellation['requestedAt'] ? '' : self::day($cancellation['requestedAt'], $language),
            'effectiveAt' => null === $cancellation['effectiveAt'] ? '' : self::moment($cancellation['effectiveAt'], $language),
            'laterThanEarliest' => null !== $cancellation['effectiveAt'] && null !== $cancellation['earliestAt'] && $cancellation['effectiveAt'] > $cancellation['earliestAt'],
            'earliestAt' => null === $cancellation['earliestAt'] ? '' : self::moment($cancellation['earliestAt'], $language),
        ];

        $sent = $this->toCustomer('cancellation', $order, self::CANCELLATION_TEMPLATE, $payload, [
            'cancellation' => $for((string) ($order['language'] ?? '')),
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);

        $this->toOperator('cancellation', $order, $shop, self::CANCELLATION_OPERATOR_TEMPLATE, $payload, [
            'cancellation' => $for($shop['fallbackLanguage']),
        ], [], $transport, $from);

        return $sent;
    }

    /**
     * The operator's decision on an extraordinary cancellation (Abo-7, P57,
     * P62): accepted with the end of the contract, or rejected with the
     * operator's reason, verbatim. No copy — the operator decided.
     *
     * @param int|null $effectiveAt the end, for an accepted one
     *
     * @return bool whether the customer's mail left
     */
    public function cancellationDecided(int $orderId, string $cancellationNumber, string $subscriptionNumber, bool $accepted, int|null $effectiveAt, string $reason, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('cancellationDecided', '', $orderId, $e);

            return false;
        }

        return $this->toCustomer('cancellationDecided', $order, self::CANCELLATION_DECIDED_TEMPLATE, $payload, [
            'cancellation' => $cancellationNumber,
            'subscription' => $subscriptionNumber,
            'accepted' => $accepted,
            'effectiveAt' => null === $effectiveAt ? '' : self::moment($effectiveAt, (string) ($order['language'] ?? '')),
            'reason' => $reason,
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);
    }

    /**
     * @param array{carrier: string, trackingNumber: string, items: list<array{quantity: string, name: string}>, complete: bool} $delivery
     *
     * @return bool whether the customer's mail left
     */
    public function shipped(int $orderId, array $delivery, int|null $invoiceId, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload, $invoice, $attachments] = $this->prepare($orderId, $invoiceId);
        } catch (\Throwable $e) {
            $this->fail('shipped', '', $orderId, $e);

            return false;
        }

        return $this->toCustomer('shipped', $order, self::SHIPPED_TEMPLATE, $payload, [
            'delivery' => $delivery,
            'invoice' => (string) ($invoice['documentNumber'] ?? ''),
            'viewUrl' => $viewUrl,
        ], $attachments, $transport, $from);
    }

    /**
     * The confirmation of receipt (D-2), and the operator's copy.
     *
     * The moment and the quantities are formatted HERE, per recipient: the
     * customer reads the order's language, the operator the shop's — and a
     * time without its zone would be a time the customer has to guess.
     *
     * @param array{number: string, receivedAt: int, source: string, name: string, email: string, scope: string, items: list<array{quantity: string, name: string}>, part: string, message: string} $withdrawal
     *
     * @return bool whether the customer's mail left
     */
    public function withdrawalReceived(int $orderId, array $withdrawal, string $viewUrl): bool
    {
        try {
            [$order, $shop, $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('withdrawal', '', $orderId, $e);

            return false;
        }

        $for = fn (string $language): array => [
            ...$withdrawal,
            'receivedAt' => self::moment($withdrawal['receivedAt'], $language),
            'items' => array_map(
                fn (array $item): array => ['quantity' => $this->money->quantity($item['quantity'], $language), 'name' => $item['name']],
                $withdrawal['items'],
            ),
        ];

        $sent = $this->toCustomer('withdrawal', $order, self::WITHDRAWAL_TEMPLATE, $payload, [
            'withdrawal' => $for((string) ($order['language'] ?? '')),
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);

        $this->toOperator('withdrawal', $order, $shop, self::WITHDRAWAL_OPERATOR_TEMPLATE, $payload, [
            'withdrawal' => $for($shop['fallbackLanguage']),
        ], [], $transport, $from);

        return $sent;
    }

    /**
     * The confirmation of a declaration that matched no order (WA, A3), and
     * the operator's copy.
     *
     * It goes to the address the consumer TYPED (§ 356a Abs. 2 Nr. 3 BGB) and
     * therefore says nothing about any order: a fixed text, the declaration
     * as it arrived, the number and the moment of receipt — what the law
     * asks for, and nothing an address typed by a stranger should learn.
     *
     * @param array{number: string, receivedAt: int, name: string, orderReference: string, email: string, scope: string, part: string, message: string} $withdrawal
     *
     * @return bool whether the consumer's mail left
     */
    public function withdrawalReceivedUnassigned(int $shopId, string $email, string $language, array $withdrawal): bool
    {
        try {
            $shop = $this->source->shop($shopId) ?? throw new \RuntimeException('the shop does not exist');
            $transportRow = $shop['transport'] ?? throw new \RuntimeException('the shop has no mail configuration');
            $transport = $this->transports->create($transportRow);
            $from = SenderAddress::of($transportRow);
        } catch (\Throwable $e) {
            $this->fail('withdrawalUnassigned', '', $withdrawal['number'], $e);

            return false;
        }

        $for = static fn (string $language): array => [...$withdrawal, 'receivedAt' => self::moment($withdrawal['receivedAt'], $language)];

        try {
            $recipient = $this->recipients->forCustomer(['email' => $email, 'firstname' => '', 'lastname' => $withdrawal['name'], 'language' => $language])[0]
                ?? throw new \RuntimeException('no e-mail address');

            $mail = $this->renderer->render(self::WITHDRAWAL_UNASSIGNED_TEMPLATE, [
                'customer' => ['name' => $recipient->name, 'email' => $recipient->email],
                'withdrawal' => $for($recipient->language),
            ], $recipient->language, $frame = $this->frames?->of($shopId, $recipient->language));

            $message = $this->email($from, $recipient, $mail, []);
            $frame?->embedInto($message);
            $transport->send($message);
            $sent = true;
        } catch (\Throwable $e) {
            $this->fail('withdrawalUnassigned', 'customer', $withdrawal['number'], $e);
            $sent = false;
        }

        try {
            $operator = $this->recipients->forOperator($shop, $shop['fallbackLanguage'])[0] ?? null;

            if (null !== $operator) {
                $mail = $this->renderer->render(self::WITHDRAWAL_UNASSIGNED_OPERATOR_TEMPLATE, [
                    'withdrawal' => $for($operator->language),
                    'confirmed' => $sent,
                ], $operator->language);

                $transport->send($this->email($from, $operator, $mail, []));
            }
        } catch (\Throwable $e) {
            $this->fail('withdrawalUnassigned', 'operator', $withdrawal['number'], $e);
        }

        return $sent;
    }

    /**
     * A rejected withdrawal (D-3, F6): the operator's reason, verbatim. No
     * copy — the operator wrote it.
     *
     * @return bool whether the customer's mail left
     */
    public function withdrawalRejected(int $orderId, string $withdrawalNumber, string $reason, string $viewUrl): bool
    {
        try {
            [$order, , $transport, $from, $payload] = $this->prepare($orderId, null);
        } catch (\Throwable $e) {
            $this->fail('withdrawalRejected', '', $orderId, $e);

            return false;
        }

        return $this->toCustomer('withdrawalRejected', $order, self::WITHDRAWAL_REJECTED_TEMPLATE, $payload, [
            'withdrawal' => $withdrawalNumber,
            'reason' => $reason,
            'viewUrl' => $viewUrl,
        ], [], $transport, $from);
    }

    /**
     * A booked refund (D-3, F4), with the documents attached that it issued —
     * the cancellation or the invoice correction, and the invoice where it was
     * issued with it (F3a).
     *
     * @param string    $payout      the amount paid back, formatted; '' where nothing is paid back (the claim fell instead)
     * @param string    $credit      the amount credited, formatted
     * @param list<int> $documentIds in the order they were issued; empty where there is none
     * @param string       $remaining     what is still to pay after this, formatted; '' where nothing is (F3b)
     * @param string       $kept          the value compensation kept back for a service, formatted; '' where none (Abo-8, P81)
     * @param list<string> $subscriptions the numbers of the subscriptions the withdrawal ended (P78, P81)
     *
     * @return bool whether the customer's mail left
     */
    public function refundBooked(int $orderId, string $withdrawalNumber, string $payout, string $credit, array $documentIds, string $viewUrl, string $remaining = '', string $kept = '', array $subscriptions = [], string $vouchers = ''): bool
    {
        try {
            [$order, , $transport, $from, $payload] = $this->prepare($orderId, null);

            $numbers = [];
            $attachments = [];

            foreach ($documentIds as $documentId) {
                $document = $this->source->document($orderId, $documentId) ?? throw new \RuntimeException(\sprintf('document %d does not exist', $documentId));

                // A document the shop's invoicing module sends, or one written
                // while invoicing was off, is neither attached nor named (R21).
                if ($this->pdfs->sentElsewhere($document['id'])) {
                    continue;
                }

                $pdf = $this->pdfs->pdf($document['id']) ?? throw new \RuntimeException(\sprintf('document %d cannot be attached', $documentId));
                $numbers[] = $document['documentNumber'];
                $attachments[] = $pdf;
            }
        } catch (\Throwable $e) {
            $this->fail('refund', '', $orderId, $e);

            return false;
        }

        return $this->toCustomer('refund', $order, self::REFUND_TEMPLATE, $payload, [
            'withdrawal' => $withdrawalNumber,
            'payout' => $payout,
            'credit' => $credit,
            'documents' => implode(', ', $numbers),
            'viewUrl' => $viewUrl,
            'remaining' => $remaining,
            'kept' => $kept,
            'subscriptions' => implode(', ', $subscriptions),
            // WG11: the part of the payout booked back onto the vouchers, formatted; '' for none.
            'vouchers' => $vouchers,
        ], $attachments, $transport, $from);
    }

    /**
     * A day, long form — "29. Oktober 2026" — in the installation's zone.
     */
    private static function day(int $timestamp, string $language): string
    {
        $locale = '' !== $language ? str_replace('-', '_', $language) : 'de';
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, date_default_timezone_get());

        return (string) $formatter->format($timestamp);
    }

    /**
     * Date AND time, with the zone (§ 356a Abs. 3 BGB: "Datum und Uhrzeit
     * des Eingangs") — "27. September 2026 um 14:03:05 MESZ".
     */
    private static function moment(int $timestamp, string $language): string
    {
        $locale = '' !== $language ? str_replace('-', '_', $language) : 'de';
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::LONG, date_default_timezone_get());

        return (string) $formatter->format($timestamp);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{operatorName: string, operatorAddress: string, fallbackLanguage: string, withdrawalDays: int, transport: array<string, mixed>|null}, 2: TransportInterface, 3: Address, 4: array<string, mixed>, 5: array<string, mixed>|null, 6: list<array{pdf: string, fileName: string}>}
     */
    private function prepare(int $orderId, int|null $invoiceId): array
    {
        $order = $this->source->order($orderId) ?? throw new \RuntimeException('the order does not exist');
        $shop = $this->source->shop((int) ($order['shop'] ?? 0)) ?? throw new \RuntimeException('the shop does not exist');
        $transportRow = $shop['transport'] ?? throw new \RuntimeException('the shop has no mail configuration');
        $transport = $this->transports->create($transportRow);
        $from = SenderAddress::of($transportRow);

        $invoice = null === $invoiceId ? null : $this->source->document($orderId, $invoiceId);
        $payload = (new OrderMailPayload($this->source, $this->payloads))->of($order, $invoice ?? $this->source->invoice($orderId));

        $attachments = [];
        $pdf = null === $invoice || $this->pdfs->sentElsewhere($invoice['id']) ? null : $this->pdfs->pdf($invoice['id']);

        if (null !== $pdf) {
            $attachments[] = $pdf;
        } elseif (null !== $invoice) {
            // The mails name the invoice as attached (`invoice`): not one
            // that is not (R21).
            $invoice['documentNumber'] = '';
        }

        return [$order, $shop, $transport, $from, $payload, $invoice, $attachments];
    }

    /**
     * @param array<string, mixed>                       $order
     * @param array<string, mixed>                       $payload
     * @param array<string, mixed>                       $variables
     * @param list<array{pdf: string, fileName: string}> $attachments
     */
    private function toCustomer(string $kind, array $order, string $template, array $payload, array $variables, array $attachments, TransportInterface $transport, Address $from): bool
    {
        try {
            $recipient = $this->recipients->forCustomer([
                'email' => (string) ($order['email'] ?? ''),
                'firstname' => (string) ($order['firstname'] ?? ''),
                'lastname' => (string) ($order['lastname'] ?? ''),
                'language' => (string) ($order['language'] ?? ''),
            ])[0] ?? throw new \RuntimeException('the order has no e-mail address');

            $mail = $this->renderer->render($template, [
                'order' => $this->view->build(['type' => 'invoice'], $payload),
                'customer' => ['name' => $recipient->name, 'email' => $recipient->email],
                ...$variables,
            ], $recipient->language, $frame = $this->frames?->of((int) ($order['shop'] ?? 0), $recipient->language));

            $email = $this->email($from, $recipient, $mail, $attachments);
            $frame?->embedInto($email);
            $transport->send($email);
        } catch (\Throwable $e) {
            $this->fail($kind, 'customer', $order['orderNumber'] ?? '?', $e);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>                                                                                               $order
     * @param array{operatorName: string, operatorAddress: string, fallbackLanguage: string, withdrawalDays: int, transport: array<string, mixed>|null} $shop
     * @param array<string, mixed>                                                                                               $payload
     * @param array<string, mixed>                                                                                               $variables
     * @param list<array{pdf: string, fileName: string}>                                                                         $attachments
     */
    private function toOperator(string $kind, array $order, array $shop, string $template, array $payload, array $variables, array $attachments, TransportInterface $transport, Address $from): void
    {
        try {
            $recipient = $this->recipients->forOperator($shop, $shop['fallbackLanguage'])[0] ?? null;

            // No operator address, no copy — a choice, not a failure.
            if (null === $recipient) {
                return;
            }

            $payload['order']['language'] = $recipient->language;

            $mail = $this->renderer->render($template, [
                'order' => $this->view->build(['type' => 'invoice'], $payload),
                'customer' => [
                    'name' => trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? '')),
                    'email' => (string) ($order['email'] ?? ''),
                ],
                ...$variables,
            ], $recipient->language);

            $transport->send($this->email($from, $recipient, $mail, $attachments));
        } catch (\Throwable $e) {
            $this->fail($kind, 'operator', $order['orderNumber'] ?? '?', $e);
        }
    }

    /**
     * @param list<array{pdf: string, fileName: string}> $attachments
     */
    private function email(Address $from, Recipient $recipient, RenderedMail $mail, array $attachments): Email
    {
        $email = (new Email())
            ->from($from)
            ->to(new Address($recipient->email, $recipient->name))
            ->subject($mail->subject)
            ->html($mail->body)
            ->text(MailText::fromHtml($mail->body))
        ;

        foreach ($attachments as $pdf) {
            $email->attach($pdf['pdf'], $pdf['fileName'], 'application/pdf');
        }

        return $email;
    }

    private function fail(string $kind, string $who, mixed $order, \Throwable $e): void
    {
        $this->logger->error(
            \sprintf('The %s mail of order %s%s could not be sent: %s', match ($kind) {
                'payment' => '"payment received"',
                'withdrawal' => '"withdrawal received"',
                // The "order" is the withdrawal's own number here — it has none.
                'withdrawalUnassigned' => '"withdrawal received, not assigned"',
                'withdrawalRejected' => '"withdrawal rejected"',
                'refund' => '"refund booked"',
                'subscriptionInvoice' => '"subscription invoice"',
                'subscriptionSuspended' => '"subscription suspended"',
                'subscriptionResumed' => '"subscription resumed"',
                'purchaseSuspended' => '"digital goods on hold"',
                'purchaseResumed' => '"digital goods available again"',
                'subscriptionPaused' => '"subscription paused"',
                'subscriptionReminder' => '"subscription reminder"',
                'cancellation' => '"cancellation received"',
                'cancellationDecided' => '"cancellation decided"',
                'digitalReleased' => '"digital content ready"',
                'documentIssued' => '"document issued"',
                default => '"shipped"',
            }, (string) $order, '' !== $who ? ' ('.$who.')' : '', $e->getMessage()),
            ['exception' => $e],
        );
    }
}

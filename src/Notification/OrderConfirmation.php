<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Webwerkwien\ContaoConshoBundle\Mail\SenderAddress;
use Symfony\Component\Mime\Email;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityGuarantees;
use Webwerkwien\ContaoConshoBundle\Catalog\GuaranteeYears;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Withdrawal\OrderPageWithdrawal;

/**
 * The order confirmation to the customer and the copy to the operator (C-9).
 *
 * ── It never throws ──────────────────────────────────────────────────────
 *
 * It runs after the purchase has committed (OrderPlacer: "A mail server that
 * is down is not a reason to lose a purchase"). Every failure is logged and
 * answered with false; `confirmationSentAt` stays empty, the back end warns,
 * and "Erneut senden" is there for the operator (7.6). The warning is a
 * state, not a log entry: it goes away with the first successful send.
 *
 * ── What the customer's mail is made of ─────────────────────────────────
 *
 * The frozen order, through the same payload builder and view as the invoice,
 * so the mail cannot show a figure the invoice does not. The payment block —
 * instructions and bank details — comes from the INVOICE SNAPSHOT where there
 * is one, never from today's payment method: a resend a year later must not
 * name an account the shop has since closed.
 *
 * ── Only the customer's mail counts ──────────────────────────────────────
 *
 * `confirmationSentAt` records what the law asks for (the confirmation on a
 * durable medium, § 7 Abs 3 FAGG). A failed operator copy is logged and does
 * not make the order "not confirmed".
 */
final class OrderConfirmation implements OrderConfirmationInterface
{
    /** The folder is part of the name — see the template. */
    public const CUSTOMER_TEMPLATE = '@Contao/mail/consho_order_confirmation.html.twig';

    public const OPERATOR_TEMPLATE = '@Contao/mail/consho_order_operator.html.twig';

    private const SENT = 'sent';
    private const FAILED = 'failed';
    private const TRANSPORT_FAILED = 'transportFailed';

    public function __construct(
        private readonly OrderConfirmationSourceInterface $source,
        private readonly MailRenderer $renderer,
        private readonly RecipientResolver $recipients,
        private readonly MailTransportFactoryInterface $transports,
        private readonly DocumentPayloadBuilder $payloads,
        private readonly InvoiceView $view,
        private readonly DocumentPdfInterface $pdfs,
        private readonly WarrantyNoticeSourceInterface $warranty,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // When the digital content comes (F-7). Optional for the tests.
        private readonly DigitalRelease|null $releases = null,
        // Block GARAN (GA4): the labels embedded. Optional for the tests.
        private readonly MailImageFile|null $images = null,
        // GARAN part 2 (L3): logo and sender in the customer's mail. Optional for the tests.
        private readonly MailFrames|null $frames = null,
    ) {
    }

    /**
     * The manufacturer's durability guarantees frozen on the order's lines
     * (block GARAN, GA6), each with its label read for embedding. A line
     * whose file is gone is left out — the sentence alone would not be the
     * harmonised label, and a broken image would claim one.
     *
     * @return array{lines: list<array{name: string, years: string, info: string, cid: string, width: int}>, files: array<string, array{name: string, contents: string, contentType: string}>} files by cid
     */
    private function guarantees(int $orderId): array
    {
        $lines = [];
        $files = [];
        $read = [];

        if (null === $this->images) {
            return ['lines' => $lines, 'files' => $files];
        }

        foreach ($this->source->items($orderId) as $item) {
            $label = $item['durabilityLabel'] ?? null;
            $years = GuaranteeYears::of($item['durabilityYears'] ?? null);

            if ('product' !== (string) ($item['lineType'] ?? '') || !GuaranteeYears::qualifies($years) || !\is_string($label) || 16 !== \strlen($label)) {
                continue;
            }

            // One part per label, however many lines share it. The name is
            // also the part's file name: with its extension, a forwarded
            // mail shows the label as an image, not a nameless attachment.
            $key = 'garan-'.bin2hex($label);
            $file = $read[$key] ??= $this->images->read($label);

            if (null === $file) {
                continue;
            }

            $cid = $key.('image/jpeg' === $file['contentType'] ? '.jpg' : '.png');
            $files[$cid] = $file;

            // Its own width up to the column's 300 px — never stretched
            // (review GARAN, 6); 300 when the bytes say nothing.
            $size = @getimagesizefromstring($file['contents']);
            $width = false === $size ? 300 : min(300, max(1, (int) $size[0]));

            $lines[] = ['name' => html_entity_decode((string) ($item['productName'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), 'years' => $years, 'info' => DurabilityGuarantees::INFO_URL, 'cid' => $cid, 'width' => $width];
        }

        return ['lines' => $lines, 'files' => $files];
    }

    /**
     * When the digital lines will be on the order page (F-7, F20) — the
     * confirmation goes out before any release, so it can only say when.
     * Null for an order without digital lines.
     *
     * @param array<string, mixed> $order
     *
     * @return array{kind: string, date: string}|null
     */
    /**
     * The withdrawal kinds of the order's product lines (Abo-2, A12, DL1),
     * from the kind frozen on each, sorted and unique.
     *
     * @return list<string>
     */
    private function withdrawalKinds(int $orderId): array
    {
        $kinds = [];

        foreach ($this->source->items($orderId) as $item) {
            $kind = 'product' === (string) ($item['lineType'] ?? '') ? ProductKind::stored((string) ($item['kind'] ?? ''))->withdrawalKind() : '';

            if ('' !== $kind) {
                $kinds[$kind] = true;
            }
        }

        $kinds = array_keys($kinds);
        sort($kinds);

        return $kinds;
    }

    private function digitalNotice(int $orderId, array $order, string $language): array|null
    {
        if (null === $this->releases) {
            return null;
        }

        $digital = array_filter(
            $this->source->items($orderId),
            // Content and services; a voucher's code has its own mail (WG-4).
            static fn (array $item): bool => 'product' === (string) ($item['lineType'] ?? '') && '' !== ProductKind::stored((string) ($item['kind'] ?? ''))->withdrawalKind(),
        );

        if ([] === $digital) {
            return null;
        }

        // DL3: the sentence names services as unlocked, content as provided.
        $services = \count(array_filter($digital, static fn (array $item): bool => ProductKind::Service === ProductKind::stored((string) ($item['kind'] ?? ''))));

        $periodEnds = $this->releases->periodEndsAt($order);
        $paid = $this->releases->paymentReady($order);
        $date = null === $periodEnds ? '' : (string) (new \IntlDateFormatter($language, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($periodEnds);

        return [
            'what' => match ($services) {
                0 => 'digital',
                \count($digital) => 'service',
                default => 'mixed',
            },
            'kind' => match (true) {
                $paid && null === $periodEnds => 'now',
                null === $periodEnds => 'afterPayment',
                $paid => 'afterPeriod',
                default => 'afterBoth',
            },
            'date' => $date,
        ];
    }

    /**
     * @param int    $rootId  the start point the order belongs to — its guarantee notice is attached
     * @param string $viewUrl the order page, token included
     * @param bool   $copyOperator false for a resend: the operator already has the order
     *
     * @return bool whether the customer's confirmation left
     */
    public function send(int $orderId, int $rootId, string $viewUrl, int $now, bool $copyOperator = true): bool
    {
        try {
            $order = $this->source->order($orderId) ?? throw new \RuntimeException('the order does not exist');
            $shop = $this->source->shop((int) ($order['shop'] ?? 0)) ?? throw new \RuntimeException('the shop does not exist');
            $transportRow = $shop['transport'] ?? throw new \RuntimeException('the shop has no mail configuration');

            $transport = $this->transports->create($transportRow);
            $from = SenderAddress::of($transportRow);

            $invoice = $this->source->invoice($orderId);
            $pdf = null === $invoice ? null : $this->pdfs->pdf($invoice['id']);
            $payload = $this->payloadOf($order, $invoice);
        } catch (\Throwable $e) {
            $this->fail($order['orderNumber'] ?? $orderId, $e);

            return false;
        }

        /*
         * The withdrawal function (D-2): the order page, opened on the
         * declaration. Only where the shop has one — a line "Vertrag
         * widerrufen" leading to a page without the function would mislead.
         */
        $withdrawalUrl = $shop['withdrawalDays'] > 0 && '' !== $viewUrl
            ? $viewUrl.(str_contains($viewUrl, '?') ? '&' : '?').OrderPageWithdrawal::FORM_PARAM.'=1'
            : null;

        $outcome = $this->toCustomer($orderId, $order, $payload, $invoice, $pdf, $rootId, $viewUrl, $withdrawalUrl, $transport, $from, $now);

        /*
         * Not over a transport that just failed (review 2026-09-25, F6): the
         * same server gives the same answer, and a second attempt would cost
         * the buyer another timeout in the purchase request.
         */
        if ($copyOperator && self::TRANSPORT_FAILED !== $outcome) {
            $this->toOperator($order, $shop, $payload, $pdf, $transport, $from);
        }

        return self::SENT === $outcome;
    }

    /**
     * @param array<string, mixed>                                                            $order
     * @param array<string, mixed>                                                            $payload
     * @param array{id: int, documentNumber: string, payload: array<string, mixed>}|null      $invoice
     * @param array{pdf: string, fileName: string, fromArchive: bool, archiveProblem: bool, logoMissing: bool}|null $pdf
     */
    private function toCustomer(
        int $orderId,
        array $order,
        array $payload,
        array|null $invoice,
        array|null $pdf,
        int $rootId,
        string $viewUrl,
        string|null $withdrawalUrl,
        TransportInterface $transport,
        Address $from,
        int $now,
    ): string {
        try {
            $recipient = $this->recipients->forCustomer([
                'email' => (string) ($order['email'] ?? ''),
                'firstname' => (string) ($order['firstname'] ?? ''),
                'lastname' => (string) ($order['lastname'] ?? ''),
                'language' => (string) ($order['language'] ?? ''),
            ])[0] ?? throw new \RuntimeException('the order has no e-mail address');

            $notice = $this->warranty->forRoot($rootId);
            $view = $this->view->build(['type' => 'invoice'], $payload);
            $guarantees = $this->guarantees($orderId);

            $mail = $this->renderer->render(self::CUSTOMER_TEMPLATE, [
                'order' => $view,
                'customer' => ['name' => $recipient->name, 'email' => $recipient->email],
                // The invoice number is the reference the Giro-Code uses as
                // well (C-8): one way to name the payment, not two.
                'reference' => null !== $invoice && [] !== $view['bankDetails'] ? $invoice['documentNumber'] : '',
                'legal' => $this->source->legalTexts($orderId),
                'viewUrl' => $viewUrl,
                'withdrawalUrl' => $withdrawalUrl,
                'attachments' => ['invoice' => null !== $pdf, 'warranty' => null !== $notice],
                // § 7 Abs. 3 FAGG, § 312f Abs. 3 BGB: the confirmation on a
                // durable medium records the consent (F-3, F15).
                'digitalConsent' => 0 < (int) ($order['digitalConsentAt'] ?? 0),
                // Which sentences (Abo-2, P14): by the withdrawal kinds frozen
                // on the order's lines, not by today's product setting.
                'digitalConsentKinds' => $this->withdrawalKinds($orderId),
                'digital' => $this->digitalNotice($orderId, $order, $recipient->language),
                'garan' => $guarantees['lines'],
            ], $recipient->language, $frame = $this->frames?->of((int) ($order['shop'] ?? 0), $recipient->language));

            $email = $this->email($from, $recipient, $mail);
            $frame?->embedInto($email);

            // Block GARAN (GA4): referenced as cid:<name> in the body.
            foreach ($guarantees['files'] as $cid => $file) {
                $email->embed($file['contents'], $cid, $file['contentType']);
            }

            if (null !== $pdf) {
                $email->attach($pdf['pdf'], $pdf['fileName'], 'application/pdf');
            }

            if (null !== $notice) {
                $email->attach($notice['contents'], $notice['name'], $notice['contentType']);
            }

            try {
                $transport->send($email);
            } catch (TransportExceptionInterface $e) {
                $this->fail($order['orderNumber'] ?? $orderId, $e);

                return self::TRANSPORT_FAILED;
            }
        } catch (\Throwable $e) {
            $this->fail($order['orderNumber'] ?? $orderId, $e);

            return self::FAILED;
        }

        /*
         * Sent, and only the record of it failed (review 2026-09-25, F5). Its
         * own message, because "could not be sent" would send the operator to
         * "Erneut senden" — and the customer would have it twice.
         */
        try {
            $this->source->markSent($orderId, $now);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The order confirmation of order %s was sent, but the time could not be recorded — do not resend it: %s', (string) ($order['orderNumber'] ?? $orderId), $e->getMessage()), ['exception' => $e]);
        }

        return self::SENT;
    }

    /**
     * @param array<string, mixed> $order
     * @param array{operatorName: string, operatorAddress: string, fallbackLanguage: string, withdrawalDays: int, transport: array<string, mixed>|null} $shop
     * @param array<string, mixed> $payload
     * @param array{pdf: string, fileName: string, fromArchive: bool, archiveProblem: bool, logoMissing: bool}|null $pdf
     */
    private function toOperator(array $order, array $shop, array $payload, array|null $pdf, TransportInterface $transport, Address $from): void
    {
        try {
            $recipient = $this->recipients->forOperator($shop, $shop['fallbackLanguage'])[0] ?? null;

            // No operator address, no copy — that is a choice, not a failure.
            if (null === $recipient) {
                return;
            }

            // Figures and dates in the SHOP's language: the operator reads
            // "13,90 €" in Vienna whatever the customer ordered in.
            $payload['order']['language'] = $recipient->language;
            $view = $this->view->build(['type' => 'invoice'], $payload);

            $mail = $this->renderer->render(self::OPERATOR_TEMPLATE, [
                'order' => $view,
                'customer' => [
                    'name' => trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? '')),
                    'email' => (string) ($order['email'] ?? ''),
                ],
                'attachments' => ['invoice' => null !== $pdf],
            ], $recipient->language);

            $email = $this->email($from, $recipient, $mail);

            if (null !== $pdf) {
                $email->attach($pdf['pdf'], $pdf['fileName'], 'application/pdf');
            }

            $transport->send($email);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The operator copy of order %s could not be sent: %s', (string) ($order['orderNumber'] ?? '?'), $e->getMessage()), ['exception' => $e]);
        }
    }

    private function email(Address $from, Recipient $recipient, RenderedMail $mail): Email
    {
        /*
         * A plain text part beside the HTML: mail clients that show only text
         * exist, and spam filters score an HTML-only mail worse.
         */
        return (new Email())
            ->from($from)
            ->to(new Address($recipient->email, $recipient->name))
            ->subject($mail->subject)
            ->html($mail->body)
            ->text(MailText::fromHtml($mail->body));
    }

    /**
     * The order as InvoiceView reads it — see OrderMailPayload, which the
     * mail after a change of payment method shares (C-10).
     *
     * @param array<string, mixed>                                                       $order
     * @param array{id: int, documentNumber: string, payload: array<string, mixed>}|null $invoice
     *
     * @return array<string, mixed>
     */
    private function payloadOf(array $order, array|null $invoice): array
    {
        return (new OrderMailPayload($this->source, $this->payloads))->of($order, $invoice);
    }

    private function fail(mixed $order, \Throwable $e): void
    {
        $this->logger->error(\sprintf('The order confirmation of order %s could not be sent: %s', (string) $order, $e->getMessage()), ['exception' => $e]);
    }
}

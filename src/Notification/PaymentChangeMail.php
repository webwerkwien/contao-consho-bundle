<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;

/**
 * The mail after a change of payment method (7.5 step 3, C-10), to the
 * customer and a copy to the operator (Michael, 2026-09-25, E4).
 *
 * The customer gets the new payment block, the cancellation and the new
 * invoice. The operator gets the copy because a transfer may still arrive
 * quoting the cancelled invoice number, and the bookkeeping has to know that
 * number no longer stands.
 *
 * Like the confirmation it never throws: the change has committed, and a mail
 * server that is down is not a reason to undo it. Unlike the confirmation it
 * records nothing — there is no legal duty behind this mail that a "sent at"
 * would prove, and the documents are on the order page either way.
 *
 * Not final: the order page's test stands a double in for it.
 */
class PaymentChangeMail
{
    public const CUSTOMER_TEMPLATE = '@Contao/mail/consho_payment_changed.html.twig';
    public const OPERATOR_TEMPLATE = '@Contao/mail/consho_payment_changed_operator.html.twig';

    public function __construct(
        private readonly OrderConfirmationSourceInterface $source,
        private readonly MailRenderer $renderer,
        private readonly RecipientResolver $recipients,
        private readonly MailTransportFactoryInterface $transports,
        private readonly DocumentPayloadBuilder $payloads,
        private readonly InvoiceView $view,
        private readonly DocumentPdfInterface $pdfs,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // GARAN part 2 (L3): logo and sender in the customer's mail. Optional for the tests.
        private readonly MailFrames|null $frames = null,
    ) {
    }

    /**
     * @param string $viewUrl the order page, token included
     *
     * @return bool whether the customer's mail left
     */
    public function send(int $orderId, int|null $cancellationId, int|null $invoiceId, string $viewUrl): bool
    {
        try {
            $order = $this->source->order($orderId) ?? throw new \RuntimeException('the order does not exist');
            $shop = $this->source->shop((int) ($order['shop'] ?? 0)) ?? throw new \RuntimeException('the shop does not exist');
            $transportRow = $shop['transport'] ?? throw new \RuntimeException('the shop has no mail configuration');
            $transport = $this->transports->create($transportRow);
            $from = new Address((string) ($transportRow['senderAddress'] ?? ''), (string) ($transportRow['senderName'] ?? ''));

            $invoice = null === $invoiceId ? null : $this->source->document($orderId, $invoiceId);
            $cancellation = null === $cancellationId ? null : $this->source->document($orderId, $cancellationId);
            $payload = (new OrderMailPayload($this->source, $this->payloads))->of($order, $invoice);

            $documents = [
                'cancelledInvoice' => (string) ($cancellation['payload']['cancels']['number'] ?? ''),
                'cancellation' => (string) ($cancellation['documentNumber'] ?? ''),
                'invoice' => (string) ($invoice['documentNumber'] ?? ''),
            ];

            // Cancellation first, then what replaces it — the order a reader
            // makes sense of them in.
            $attachments = [];

            foreach ([$cancellation, $invoice] as $document) {
                $pdf = null === $document ? null : $this->pdfs->pdf($document['id']);

                if (null !== $pdf) {
                    $attachments[] = $pdf;
                }
            }
        } catch (\Throwable $e) {
            $this->fail('', $order['orderNumber'] ?? $orderId, $e);

            return false;
        }

        $sent = $this->toCustomer($order, $payload, $documents, $attachments, $viewUrl, $transport, $from);
        $this->toOperator($order, $shop, $payload, $documents, $attachments, $transport, $from);

        return $sent;
    }

    /**
     * @param array<string, mixed>                                    $order
     * @param array<string, mixed>                                    $payload
     * @param array{cancelledInvoice: string, cancellation: string, invoice: string} $documents
     * @param list<array{pdf: string, fileName: string}>              $attachments
     */
    private function toCustomer(array $order, array $payload, array $documents, array $attachments, string $viewUrl, TransportInterface $transport, Address $from): bool
    {
        try {
            $recipient = $this->recipients->forCustomer([
                'email' => (string) ($order['email'] ?? ''),
                'firstname' => (string) ($order['firstname'] ?? ''),
                'lastname' => (string) ($order['lastname'] ?? ''),
                'language' => (string) ($order['language'] ?? ''),
            ])[0] ?? throw new \RuntimeException('the order has no e-mail address');

            $view = $this->view->build(['type' => 'invoice'], $payload);

            $mail = $this->renderer->render(self::CUSTOMER_TEMPLATE, [
                'order' => $view,
                'customer' => ['name' => $recipient->name, 'email' => $recipient->email],
                // The new invoice number, as in the confirmation and the
                // Giro-Code: the old one no longer stands.
                'reference' => [] !== $view['bankDetails'] ? $documents['invoice'] : '',
                'documents' => $documents,
                'viewUrl' => $viewUrl,
            ], $recipient->language, $frame = $this->frames?->of((int) ($order['shop'] ?? 0), $recipient->language));

            $email = $this->email($from, $recipient, $mail, $attachments);
            $frame?->embedInto($email);
            $transport->send($email);
        } catch (\Throwable $e) {
            $this->fail('customer', $order['orderNumber'] ?? '?', $e);

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>                                    $order
     * @param array{operatorName: string, operatorAddress: string, fallbackLanguage: string, withdrawalDays: int, transport: array<string, mixed>|null} $shop
     * @param array<string, mixed>                                    $payload
     * @param array{cancelledInvoice: string, cancellation: string, invoice: string} $documents
     * @param list<array{pdf: string, fileName: string}>              $attachments
     */
    private function toOperator(array $order, array $shop, array $payload, array $documents, array $attachments, TransportInterface $transport, Address $from): void
    {
        try {
            $recipient = $this->recipients->forOperator($shop, $shop['fallbackLanguage'])[0] ?? null;

            // No operator address, no copy — a choice, not a failure.
            if (null === $recipient) {
                return;
            }

            $payload['order']['language'] = $recipient->language;

            $mail = $this->renderer->render(self::OPERATOR_TEMPLATE, [
                'order' => $this->view->build(['type' => 'invoice'], $payload),
                'customer' => [
                    'name' => trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? '')),
                    'email' => (string) ($order['email'] ?? ''),
                ],
                'documents' => $documents,
            ], $recipient->language);

            $transport->send($this->email($from, $recipient, $mail, $attachments));
        } catch (\Throwable $e) {
            $this->fail('operator', $order['orderNumber'] ?? '?', $e);
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

    private function fail(string $who, mixed $order, \Throwable $e): void
    {
        $this->logger->error(
            \sprintf('The mail about the changed payment method of order %s%s could not be sent: %s', (string) $order, '' !== $who ? ' ('.$who.')' : '', $e->getMessage()),
            ['exception' => $e],
        );
    }
}

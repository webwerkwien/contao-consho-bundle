<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;

/**
 * The order as a mail shows it, in the shape InvoiceView reads (C-9).
 *
 * Moved out of OrderConfirmation in C-10, because the mail after a change of
 * payment method shows the same order the same way — and a second copy of
 * where the payment block comes from would be free to name a different
 * account than the invoice beside it.
 */
final class OrderMailPayload
{
    public function __construct(
        private readonly OrderConfirmationSourceInterface $source,
        private readonly DocumentPayloadBuilder $payloads,
    ) {
    }

    /**
     * From the frozen order rows, with the payment block, the operator's notes
     * (the reverse charge note) and the expected delivery period of the
     * standing invoice — all frozen at its issue.
     * Without an invoice there are none.
     *
     * @param array<string, mixed>                                                       $order
     * @param array{id: int, documentNumber: string, payload: array<string, mixed>}|null $invoice
     *
     * @return array<string, mixed>
     */
    public function of(array $order, array|null $invoice): array
    {
        $payment = \is_array($invoice['payload']['payment'] ?? null) ? $invoice['payload']['payment'] : [];

        // Without an invoice (a method that invoices on payment), the
        // instructions frozen on the order itself.
        $instructions = (string) ($payment['instructions'] ?? '');

        if ('' === $instructions) {
            $instructions = (string) ($order['paymentInstructions'] ?? '');
        }

        $notes = \is_array($invoice['payload']['notes'] ?? null) ? $invoice['payload']['notes'] : [];
        $delivery = $invoice['payload']['delivery'] ?? null;
        $subscription = $invoice['payload']['subscription'] ?? null;

        return $this->payloads->build(
            array_map(static fn (mixed $value): string => (string) $value, array_filter($order, static fn (mixed $value): bool => \is_scalar($value) || null === $value)),
            array_map(
                static fn (array $item): array => array_map(static fn (mixed $value): string => (string) $value, array_filter($item, static fn (mixed $value): bool => \is_scalar($value) || null === $value)),
                $this->source->items((int) $order['id']),
            ),
            [
                'payment' => [
                    'instructions' => $instructions,
                    'details' => \is_array($payment['details'] ?? null) ? array_map('strval', $payment['details']) : [],
                ],
                'notes' => [
                    'footer' => (string) ($notes['footer'] ?? ''),
                    'reverseCharge' => (string) ($notes['reverseCharge'] ?? ''),
                ],
                'delivery' => \is_array($delivery) && isset($delivery['from'], $delivery['to'])
                    ? ['from' => (string) $delivery['from'], 'to' => (string) $delivery['to']]
                    : null,
                // Abo-4: the subscription and service period as the invoice
                // states them.
                'subscription' => \is_array($subscription) && isset($subscription['number'], $subscription['from'], $subscription['to'])
                    ? ['number' => (string) $subscription['number'], 'from' => (string) $subscription['from'], 'to' => (string) $subscription['to']]
                    : null,
            ],
        );
    }
}

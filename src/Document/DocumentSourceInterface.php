<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

/**
 * What an invoice says beyond the order itself (8.1): who issues it, with
 * which logo, how to pay, and the operator's own texts — all read at the
 * moment of issuing, in the language of the order, and frozen into the
 * snapshot by DocumentPayloadBuilder.
 */
interface DocumentSourceInterface
{
    /**
     * @param array<string, string> $order the order row
     *
     * @return array{
     *     issuer: array<string, string>,
     *     logo: array{uuid: string, hash: string}|null,
     *     payment: array{instructions: string, details: array<string, string>, giroCode?: bool, dueDays?: int|null, meansCode?: string},
     *     notes: array{footer: string, reverseCharge: string},
     *     deliveryDays?: array{min: int, max: int},
     *     shipment?: array{shippedAt: int, complete: bool, remainderDays: int}|null,
     *     subscription?: array{number: string, from: string, to: string}|null,
     *     payments?: list<array{method: string, amount: string}>,
     *     eInvoice?: bool,
     * }
     */
    public function contextFor(array $order): array;
}

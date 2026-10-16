<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

interface DocumentIssuerInterface
{
    /**
     * @param bool $giroCode false: an invoice without the Giro-Code, where the
     *                       amount it states is not the amount to pay (D-3, F3b)
     *
     * @throws RelatedDocumentRequired
     */
    public function issue(int $orderId, int $shopId, DocumentType $type, int|null $relatedDocumentId = null, bool $giroCode = true): int;

    /**
     * Issues the document only if none of this type exists for the order yet.
     *
     * @throws RelatedDocumentRequired
     */
    public function issueOnce(int $orderId, int $shopId, DocumentType $type, int|null $relatedDocumentId = null, bool $giroCode = true): int;

    public function hasDocument(int $orderId, DocumentType $type): bool;

    /**
     * Cancels an invoice from its own snapshot; returns the cancellation, the
     * existing one if there is one.
     */
    public function cancel(int $invoiceId): int;

    /**
     * An invoice correction (D-3, decision F2 — internally `CreditNote`): the
     * given lines, amounts already negative, against the invoice's own
     * snapshot for everything else.
     *
     * @param list<array<string, string>> $lines in the snapshot's item shape
     */
    public function correct(int $invoiceId, array $lines, string $withdrawalNumber): int;
}

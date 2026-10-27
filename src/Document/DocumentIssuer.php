<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\Invoicing;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\Order\NumberAllocatorInterface;

/**
 * Issues documents.
 *
 * Every type draws from its own number range, and the number is allocated only
 * after every check has passed — a refused document must never leave a hole in
 * a sequence that has to be unbroken.
 *
 * ── Where Consho does not issue the invoice (block R, R15–R21) ────────────
 *
 * A shop may have its invoices issued by a module or outside Consho ("off").
 * The document row is written all the same, with its full snapshot: the
 * payment term, the OSS return, withdrawals and the address lock read it, and
 * the operator asked for the invoice data to be kept in every mode (R16).
 * Only what needs Consho's number is left out — the number itself and the
 * Giro-Code, which carries it as its reference. A module numbers its
 * documents after the commit (ExternalInvoicing).
 *
 * A cancellation or credit note follows the issuer of its invoice, not the
 * shop's setting today (R20): an invoice Consho numbered is reversed by a
 * document Consho numbers, or its range would carry an invoice nothing ever
 * reversed.
 */
final class DocumentIssuer implements DocumentIssuerInterface
{
    public function __construct(
        private readonly DocumentRepositoryInterface $repository,
        private readonly NumberAllocatorInterface $allocator,
        private readonly DocumentPayloadBuilder $payloadBuilder,
        private readonly DocumentSourceInterface $source,
        private readonly InvoicingPolicy|null $policy = null,
    ) {
    }

    /**
     * @throws RelatedDocumentRequired
     */
    public function issue(int $orderId, int $shopId, DocumentType $type, int|null $relatedDocumentId = null, bool $giroCode = true): int
    {
        /*
         * Check first, allocate second.
         *
         * The other way round, every refused document would burn a number. An
         * invoice sequence with holes in it is exactly what the tax office does
         * not accept, and nothing later can put the number back — the allocator
         * only ever counts up.
         */
        if ($type->requiresRelatedDocument() && (null === $relatedDocumentId || $relatedDocumentId < 1)) {
            throw RelatedDocumentRequired::for($type);
        }

        $order = $this->repository->findOrderRow($orderId);
        $issuedAt = time();
        $context = $this->source->contextFor($order);

        /*
         * The expected delivery period (C-8): an invoice issued at the
         * purchase names when the goods are expected, counted from the day it
         * is issued. Only the invoice — a credit note or a cancellation
         * refers to an invoice that already says it.
         */
        if (DocumentType::Invoice === $type) {
            $shipment = $context['shipment'] ?? null;

            /*
             * Once a parcel has gone (E-6b): the day of
             * delivery is known and is what the invoice owes (§ 11 Abs. 1 Z 3
             * lit. d UStG 1994, § 14 Abs. 4 Satz 1 Nr. 6 UStG) — after a
             * partial shipment with the day the rest is expected.
             */
            if (\is_array($shipment)) {
                $context['delivery'] = DeliveryPeriod::shipped($shipment['shippedAt'], $shipment['complete'], $shipment['remainderDays']);
            } else {
                $days = $context['deliveryDays'] ?? ['min' => 0, 'max' => 0];
                $period = DeliveryPeriod::of($issuedAt, $days['min'], $days['max']);
                $context['delivery'] = null === $period ? null : [...$period, 'kind' => 'expected'];

                // L-1: paid before the goods go out — the invoice covers an
                // advance payment and says so (formatVersion 11).
                if (null !== $context['delivery'] && true === ($context['payment']['advance'] ?? false)) {
                    $context['delivery']['advance'] = true;
                }
            }
        }

        $payload = $this->payloadBuilder->build(
            $order,
            $this->repository->findOrderItemRows($orderId),
            $context,
        );

        // The delivery note is always Consho's; only the invoice asks the shop.
        $invoicing = DocumentType::Invoice === $type && null !== $this->policy
            ? $this->policy->forShop($shopId)
            : null;
        $numbered = null === $invoicing || $invoicing->conshoNumbers;
        $number = $numbered ? $this->allocator->next($shopId, $type->numberRange()) : null;

        /*
         * What is still to pay (WG12, formatVersion 9). An invoice asked for
         * the grand total even where part of it was paid already — after a
         * voucher at the purchase, or a part payment before the invoice "on
         * shipment". The payments so far go into the snapshot with what
         * remains, and the Giro-Code and the term ask for that remainder; an
         * invoice with nothing open asks for nothing.
         */
        $asksPayment = $giroCode && DocumentType::Invoice === $type;
        $owed = (string) ($payload['order']['grandTotal'] ?? '');

        if ($asksPayment && [] !== ($context['payments'] ?? [])) {
            $paid = BigDecimal::zero();

            foreach ($context['payments'] as $payment) {
                $paid = $paid->plus($payment['amount']);
            }

            $owed = (string) BigDecimal::of('' === $owed ? '0' : $owed)->minus($paid)->toScale(4);
            $payload['payment']['payments'] = $context['payments'];
            $payload['payment']['outstanding'] = $owed;
        }

        $asksMoney = $asksPayment && ('' === $owed || BigDecimal::of($owed)->isPositive());

        /*
         * The Giro-Code (C-8) carries the invoice number as its reference, so
         * it is built only now — after the number, which is fine because
         * GiroCode::payload() refuses nothing: it returns null, and the
         * invoice goes out without a code. Only an invoice asks for payment;
         * a credit note or a cancellation carries none.
         *
         * Nor does the invoice issued with a refund (D-3, F3b): it states the
         * whole order and is corrected at once, so its total is not what the
         * customer has to pay — a code with that amount would ask for too
         * much. The refund mail names the remaining amount instead.
         *
         * Nor without Consho's number (R16): the code's reference would be
         * empty, and a module prints its own.
         */
        if (null !== $number && $asksMoney && ($context['payment']['giroCode'] ?? false)) {
            $code = GiroCode::payload(
                $payload['payment']['details'],
                $owed,
                $payload['order']['currency'] ?? '',
                $number,
            );

            if (null !== $code) {
                $payload['payment']['giroCode'] = $code;
            }
        }

        /*
         * The payment term (E-2, Z1): "Zahlbar bis" as a date, frozen like
         * the delivery period — the snapshot keeps the answer, not the rule.
         * Same condition as the Giro-Code: only an invoice that asks for
         * payment names a term, not the one issued with a refund.
         *
         * In every mode (R17): without it an invoice issued elsewhere would
         * never be overdue and a subscription never rest for it.
         */
        $dueAt = 0;

        if ($asksMoney && \is_int($context['payment']['dueDays'] ?? null)) {
            $due = PaymentTerm::of($issuedAt, $context['payment']['dueDays']);
            $payload['payment']['dueDate'] = $due['date'];
            $dueAt = $due['endsAt'];
        }

        $documentId = $this->repository->insert(
            $orderId,
            $shopId,
            $type,
            $number,
            $issuedAt,
            $relatedDocumentId ?? 0,
            $payload,
            null === $invoicing ? Invoicing::CONSHO : $invoicing->issuer,
            null !== $invoicing && $invoicing->moduleIssues() ? 'pending' : '',
        );

        // Every invoice that asks for payment writes it, a term-less one 0:
        // after a change of payment method the old term must not stay behind.
        if ($asksPayment) {
            $this->repository->recordPaymentDue($orderId, $dueAt);
        }

        return $documentId;
    }

    /**
     * Cancels an invoice (7.5): a cancellation from the invoice's own
     * snapshot, with the other sign and a reference to it.
     *
     * Once per invoice. Asked again, it answers with the cancellation that
     * exists — a second one would cancel the invoice twice, and the two
     * sequences would each carry a document that means nothing.
     *
     * Like issue(): checked first, the number drawn last.
     */
    public function cancel(int $invoiceId): int
    {
        $invoice = $this->repository->findDocumentRow($invoiceId);

        if (null === $invoice || DocumentType::Invoice->value !== ($invoice['type'] ?? '')) {
            throw new \InvalidArgumentException(\sprintf('Document %d is not an invoice and cannot be cancelled.', $invoiceId));
        }

        $existing = $this->repository->findCancellationOf($invoiceId);

        if (null !== $existing) {
            return $existing;
        }

        $snapshot = json_decode((string) ($invoice['payload'] ?? ''), true);

        if (!\is_array($snapshot)) {
            // A cancellation of a snapshot that cannot be read would be a
            // document stating nothing — the one shape that proves nothing.
            throw new \UnexpectedValueException(\sprintf('The snapshot of invoice %d cannot be read.', $invoiceId));
        }

        $payload = $this->payloadBuilder->cancellationOf(
            $snapshot,
            (string) $invoice['documentNumber'],
            (int) $invoice['issuedAt'],
        );

        $shopId = (int) $invoice['shop'];
        [$issuer, $externalState] = self::followerOf($invoice);
        $number = Invoicing::CONSHO === $issuer ? $this->allocator->next($shopId, DocumentType::Cancellation->numberRange()) : null;

        return $this->repository->insert(
            (int) $invoice['pid'],
            $shopId,
            DocumentType::Cancellation,
            $number,
            time(),
            $invoiceId,
            $payload,
            $issuer,
            $externalState,
        );
    }

    /**
     * An invoice correction (D-3, decisions F2/F3): the refunded lines of a
     * withdrawal, negative, related to the invoice they correct.
     *
     * Built on the invoice's SNAPSHOT for issuer, customer, logo and notes —
     * like the cancellation, and for the same reason: the correction comes
     * from the issuer that invoiced, not from today's shop. Only the lines are
     * new; they come from the withdrawal (WithdrawalRefund).
     *
     * Not once per invoice, unlike the cancellation: two withdrawals of one
     * order are two corrections.
     *
     * @param list<array<string, string>> $lines
     * @param list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}> $prepaid what goes back onto single-purpose vouchers, negative (EZ-4)
     */
    public function correct(int $invoiceId, array $lines, string $withdrawalNumber, array $prepaid = []): int
    {
        $invoice = $this->repository->findDocumentRow($invoiceId);

        if (null === $invoice || DocumentType::Invoice->value !== ($invoice['type'] ?? '')) {
            throw new \InvalidArgumentException(\sprintf('Document %d is not an invoice and cannot be corrected.', $invoiceId));
        }

        if ([] === $lines) {
            throw new \InvalidArgumentException(\sprintf('A correction of invoice %d needs at least one line.', $invoiceId));
        }

        $snapshot = json_decode((string) ($invoice['payload'] ?? ''), true);

        if (!\is_array($snapshot)) {
            throw new \UnexpectedValueException(\sprintf('The snapshot of invoice %d cannot be read.', $invoiceId));
        }

        $payload = $this->payloadBuilder->correctionOf(
            $snapshot,
            (string) $invoice['documentNumber'],
            (int) $invoice['issuedAt'],
            $lines,
            $withdrawalNumber,
            $prepaid,
        );

        $shopId = (int) $invoice['shop'];
        [$issuer, $externalState] = self::followerOf($invoice);
        $number = Invoicing::CONSHO === $issuer ? $this->allocator->next($shopId, DocumentType::CreditNote->numberRange()) : null;

        return $this->repository->insert(
            (int) $invoice['pid'],
            $shopId,
            DocumentType::CreditNote,
            $number,
            time(),
            $invoiceId,
            $payload,
            $issuer,
            $externalState,
        );
    }

    /**
     * Issuer and handover state of a cancellation or credit note: those of
     * its invoice (R20). A row without the column is Consho's — documents
     * from before R-6.
     *
     * @param array<string, string> $invoice
     *
     * @return array{0: string, 1: string}
     */
    private static function followerOf(array $invoice): array
    {
        $issuer = '' === ($invoice['issuer'] ?? '') ? Invoicing::CONSHO : $invoice['issuer'];

        return [$issuer, Invoicing::CONSHO === $issuer || Invoicing::OFF === $issuer ? '' : 'pending'];
    }

    /**
     * Issues the document only if none of this type exists for the order yet.
     *
     * Used by the trigger points: an invoice must not be issued twice because a
     * payment notification arrived twice — and payment providers do send the
     * same notification twice.
     */
    public function issueOnce(int $orderId, int $shopId, DocumentType $type, int|null $relatedDocumentId = null, bool $giroCode = true): int
    {
        $existing = $this->repository->findIdByOrderAndType($orderId, $type);

        if (null !== $existing) {
            return $existing;
        }

        return $this->issue($orderId, $shopId, $type, $relatedDocumentId, $giroCode);
    }

    public function hasDocument(int $orderId, DocumentType $type): bool
    {
        return null !== $this->repository->findIdByOrderAndType($orderId, $type);
    }
}

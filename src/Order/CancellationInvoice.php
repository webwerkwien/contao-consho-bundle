<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;

/**
 * The cancellation invoice of a cancelling status (dev#1; spec A: `isCancelled`
 * → "Bestandsrückgabe, Stornobeleg").
 *
 * Until then only the payment change and a full withdrawal cancelled an
 * invoice. An order cancelled through its status gave its stock back, but a
 * prepayment invoice issued at the purchase stayed standing — an invoice for a
 * sale that never happened, with nothing in the books to say so.
 *
 * ── What it refuses ──────────────────────────────────────────────────────
 *
 * A cancellation reverses the WHOLE invoice. Where an invoice correction
 * already credited part of it (a partial withdrawal before shipping), a full
 * reversal would credit that part twice. There is no "cancel the rest": the
 * rest is a withdrawal of the remaining items, which issues its own
 * correction. So the back end refuses the status, and says why. Likewise
 * without a cancellation number range — "Nichts wurde gebucht", as
 * "Erstattung buchen" does.
 *
 * An automatic change (ContaoOrderStatusWriter) cannot be refused — it rides
 * on a payment or a delivery that already happened. There the invoice stays
 * and the error log says why. An automatic status that cancels is a
 * misconfiguration in its own right, which the findings already report
 * (OrderStatusIssueSource, AutomaticStatusCancels).
 *
 * An order cancelled already is not refused:
 * OrderCancellation does nothing a second time, so there is nothing a
 * refusal would protect — it would only block re-saving the status of an
 * order cancelled before dev#1.
 */
class CancellationInvoice
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentRepositoryInterface $documents,
        private readonly DocumentIssuerInterface $issuer,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Why a cancelling status may not be set on this order — null when it may.
     */
    public function refusal(int $orderId): CancellationInvoiceRefusal|null
    {
        $cancelled = $this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = ?', [$orderId]);

        if (false === $cancelled || null !== $cancelled) {
            return null;
        }

        $invoiceId = $this->documents->findIdByOrderAndType($orderId, DocumentType::Invoice);

        return null === $invoiceId ? null : $this->refusalFor($orderId, $invoiceId);
    }

    /**
     * Cancels the order's open invoice. Called inside the cancellation's
     * transaction; the caller archives the document after the commit.
     *
     * @return int|null the cancellation document, null where there was nothing to cancel or it was refused
     */
    public function issue(int $orderId): int|null
    {
        $invoiceId = $this->documents->findIdByOrderAndType($orderId, DocumentType::Invoice);

        if (null === $invoiceId) {
            return null;
        }

        $refusal = $this->refusalFor($orderId, $invoiceId);

        if (null !== $refusal) {
            $this->logger->error(\sprintf(
                'Order %d was cancelled, but its invoice %d was not: %s. The invoice stays standing.',
                $orderId,
                $invoiceId,
                CancellationInvoiceRefusal::Corrected === $refusal
                    ? 'an invoice correction already credits part of it'
                    : 'the shop has no number range for cancellations',
            ));

            return null;
        }

        return $this->issuer->cancel($invoiceId);
    }

    private function refusalFor(int $orderId, int $invoiceId): CancellationInvoiceRefusal|null
    {
        if ($this->corrected($invoiceId)) {
            return CancellationInvoiceRefusal::Corrected;
        }

        if (!$this->hasRange($orderId)) {
            return CancellationInvoiceRefusal::NumberRangeMissing;
        }

        return null;
    }

    private function corrected(int $invoiceId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_document WHERE relatedDocument = ? AND type = ?',
            [$invoiceId, DocumentType::CreditNote->value],
        );
    }

    private function hasRange(int $orderId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_number_range r JOIN tl_consho_order o ON o.shop = r.pid WHERE o.id = ? AND r.type = ?',
            [$orderId, DocumentType::Cancellation->numberRange()],
        );
    }
}

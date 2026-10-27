<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Webwerkwien\ContaoConshoBundle\Document\DocumentType;

/**
 * A document Consho wrote but did not number, as an issuing module receives
 * it (R18).
 *
 * The snapshot is the one Consho would have printed — the same shape as an
 * IssuedDocument's, without number, Giro-Code and anything else that only
 * exists once a number does. A cancellation or credit note names the external
 * id of the invoice it refers to.
 */
final class PendingDocument
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public readonly int $id,
        public readonly int $shopId,
        public readonly int $orderId,
        public readonly DocumentType $type,
        public readonly array $snapshot,
        public readonly string $relatedExternalId = '',
    ) {
    }
}

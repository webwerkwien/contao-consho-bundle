<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

/**
 * The outcome of one VAT ID check, with the evidence that it happened.
 *
 * `requestIdentifier` is what VIES issues as proof of the consultation. In a
 * tax audit it is the record showing the ID really was verified at the time
 * the invoice was written — which is why it is stored, not just read.
 */
final class VatIdCheckResult
{
    public function __construct(
        public readonly VatIdStatus $status,
        public readonly int $checkedAt,
        public readonly string|null $name = null,
        public readonly string|null $requestIdentifier = null,
    ) {
    }
}

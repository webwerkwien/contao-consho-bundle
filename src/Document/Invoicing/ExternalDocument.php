<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

/**
 * What an issuing module reports back (R18): its own id for the document, the
 * number it printed and the time it issued it. Consho stores the number with
 * the document, so mails, the order page and the tax report name it.
 */
final class ExternalDocument
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $number,
        public readonly int $issuedAt,
    ) {
    }
}

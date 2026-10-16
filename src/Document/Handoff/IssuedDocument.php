<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Handoff;

use Webwerkwien\ContaoConshoBundle\Document\DocumentType;

/**
 * A document as handed to an accounting module (block R, R13): what it is,
 * its snapshot, and the PDF the customer received — rendered only when a
 * module asks for it.
 */
final class IssuedDocument
{
    private string|null $pdf = null;

    /**
     * @param array<string, mixed> $payload the snapshot, as frozen at the issue
     * @param \Closure(): string    $render
     */
    public function __construct(
        public readonly int $id,
        public readonly int $shopId,
        public readonly int $orderId,
        public readonly DocumentType $type,
        public readonly string $number,
        public readonly int $issuedAt,
        public readonly array $payload,
        private readonly \Closure $render,
    ) {
    }

    /**
     * The PDF — the archived file where the shop keeps one, otherwise
     * rendered from the snapshot, with the e-invoice XML where it carries one.
     */
    public function pdf(): string
    {
        return $this->pdf ??= ($this->render)();
    }
}

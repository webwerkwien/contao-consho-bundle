<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use Webwerkwien\ContaoConshoBundle\Document\Handoff\IssuedDocument;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\ExternalDocument;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviderInterface;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\PendingDocument;

/**
 * An invoicing module for tests: records what it receives, numbers FB-1,
 * FB-2 …, and can be told to issue itself, send itself or fail.
 */
final class FakeInvoiceProvider implements InvoiceProviderInterface
{
    /** @var list<PendingDocument> */
    public array $issued = [];

    /** @var list<IssuedDocument> */
    public array $handedOver = [];

    public \Throwable|null $failWith = null;

    public function __construct(
        public bool $issues = true,
        public bool $sends = false,
        public string|null $pdf = '%PDF-fake',
    ) {
    }

    public static function getName(): string
    {
        return 'fake_books';
    }

    public function label(): string
    {
        return 'Fake Books';
    }

    public function issuesItself(int $shopId): bool
    {
        return $this->issues;
    }

    public function sendsItself(int $shopId): bool
    {
        return $this->sends;
    }

    public function issue(PendingDocument $document): ExternalDocument
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }

        $this->issued[] = $document;
        $n = \count($this->issued);

        return new ExternalDocument('ext-'.$n, 'FB-'.$n, 1_800_000_000 + $n);
    }

    public function pdf(int $shopId, string $externalId): string|null
    {
        return $this->pdf;
    }

    public function handOver(IssuedDocument $document): void
    {
        $this->handedOver[] = $document;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

/**
 * How a shop's invoices come about (R15–R21), as InvoicingPolicy reads it.
 *
 * `issuer` is what the document row records: 'consho' wherever Consho draws
 * the number — also for a module that only passes Consho's invoices on —,
 * otherwise 'off' or the module's name. `provider` is null for Consho, for
 * "off" and for a module that is no longer installed.
 */
final class Invoicing
{
    public const CONSHO = 'consho';

    public const OFF = 'off';

    public function __construct(
        public readonly string $issuer,
        public readonly InvoiceProviderInterface|null $provider,
        public readonly bool $conshoNumbers,
        public readonly bool $conshoSends,
    ) {
    }

    /**
     * A module issues the documents and reports their numbers after the
     * commit — also one that is not installed: its documents wait.
     */
    public function moduleIssues(): bool
    {
        return !$this->conshoNumbers && self::OFF !== $this->issuer;
    }
}

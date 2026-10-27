<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\EInvoice;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\EInvoiceListener;

/**
 * E-invoices switched on, a required detail emptied since (block R, R10):
 * the mask checks only when the switch is saved. Without the detail the
 * invoices go out as plain PDFs (ZugferdInvoice), which a business that
 * must receive e-invoices may refuse — a warning.
 *
 * Only where Consho numbers the invoices (R19): the XML travels in Consho's
 * PDF, and a module that issues its own makes its own.
 */
final class EInvoiceIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InvoicingPolicy|null $policy = null,
    ) {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, '.implode(', ', EInvoiceListener::REQUIRED).' FROM tl_consho_shop WHERE eInvoice = 1 ORDER BY id') as $shop) {
            $missing = array_values(array_filter(EInvoiceListener::REQUIRED, static fn (string $field): bool => '' === trim((string) ($shop[$field] ?? ''))));

            if ([] === $missing || !$this->conshoNumbers((int) $shop['id'])) {
                continue;
            }

            // default.php, which the start page has loaded — not the shop's own file.
            $lang = $GLOBALS['TL_LANG']['CONSHO']['eInvoiceFields'] ?? [];
            $issues[] = new ConfigurationIssue(ConfigurationFinding::EInvoiceIncomplete, [
                'shop' => (string) $shop['title'],
                'fields' => implode(', ', array_map(static fn (string $field): string => (string) ($lang[$field] ?? $field), $missing)),
            ], (int) $shop['id']);
        }

        return $issues;
    }

    /**
     * Whether Consho numbers this shop's invoices — itself or for a module
     * that passes them on (block R, R19). Only then does it need the invoice
     * ranges, the sender and what else goes on Consho's invoice.
     */
    private function conshoNumbers(int $shopId): bool
    {
        return null === $this->policy || $this->policy->forShop($shopId)->conshoNumbers;
    }
}

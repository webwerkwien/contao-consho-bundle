<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\EInvoice;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\EInvoiceListener;

/**
 * E-invoices switched on, a required detail emptied since (block R, R10):
 * the mask checks only when the switch is saved. Without the detail the
 * invoices go out as plain PDFs (ZugferdInvoice), which a business that
 * must receive e-invoices may refuse — a warning.
 */
final class EInvoiceIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, '.implode(', ', EInvoiceListener::REQUIRED).' FROM tl_consho_shop WHERE eInvoice = 1 ORDER BY id') as $shop) {
            $missing = array_values(array_filter(EInvoiceListener::REQUIRED, static fn (string $field): bool => '' === trim((string) ($shop[$field] ?? ''))));

            if ([] === $missing) {
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
}

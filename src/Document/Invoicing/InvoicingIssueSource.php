<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Who issues the invoices, as findings (block R, R15, R18):
 *
 * - "off": said on the start page, so that the invoices the shop owes are
 *   not forgotten — Consho stores their data and nothing else;
 * - a module that is no longer installed: its shop's invoices wait;
 * - documents that did not reach the module — failed, or left waiting or
 *   half submitted for longer than a request takes.
 *
 * The count only, no amounts: the start page is seen by more people than the
 * order list.
 */
final class InvoicingIssueSource implements ConfigurationIssueSource
{
    /**
     * How long a document may wait for its module before it counts as stuck:
     * the module is asked after the commit of the request that issued it, so
     * anything older than this did not get there.
     */
    private const STUCK_AFTER = 3600;

    public function __construct(
        private readonly Connection $connection,
        private readonly InvoiceProviders $providers,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative("SELECT id, title, invoicing FROM tl_consho_shop WHERE invoicing NOT IN ('', 'consho') ORDER BY id") as $shop) {
            $name = (string) $shop['invoicing'];

            if (Invoicing::OFF === $name) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::InvoicingOff, ['shop' => (string) $shop['title']], (int) $shop['id']);
            } elseif (null === $this->providers->get($name)) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::InvoiceProviderMissing, ['shop' => (string) $shop['title'], 'module' => $name], (int) $shop['id']);
            }
        }

        $stuck = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_consho_document
              WHERE externalState = 'failed'
                 OR (externalState IN ('pending', 'submitting') AND tstamp < ?)",
            [$this->now() - self::STUCK_AFTER],
        );

        if ($stuck > 0) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::ExternalInvoiceFailed, ['count' => (string) $stuck]);
        }

        return $issues;
    }

    private function now(): int
    {
        return null === $this->clock ? time() : (int) ($this->clock)();
    }
}

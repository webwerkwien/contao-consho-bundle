<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Checkout\OnOrderInvoicing;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;

/**
 * The invoice findings of C-8 (11.3): a failed archive, an archive folder
 * that is missing or public, and a reverse charge that can happen without
 * the operator's note.
 *
 * One count query for the failed archives, one query over the shops — the
 * back end asks for findings several times per request (B, section 10). The
 * folder check touches the file system once per ARCHIVING shop, which is a
 * handful of stat calls, not a walk over the archive; whether the archived
 * PDFs are intact is `consho:document:verify`'s job, not the start page's.
 */
final class DbalInvoiceIssueSource implements ConfigurationIssueSource
{
    /**
     * Number of document numbers named in the finding. The count says how
     * many; the names say where to start looking.
     */
    private const EXAMPLES = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly ArchiveFolderProtector $protector,
        private readonly PaymentHandlers $handlers,
    ) {
    }

    public function issues(): array
    {
        $issues = [];

        $failed = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_document WHERE archiveState = 'failed'");

        if ($failed > 0) {
            $numbers = $this->connection->fetchFirstColumn(
                "SELECT documentNumber FROM tl_consho_document WHERE archiveState = 'failed' ORDER BY id LIMIT ".self::EXAMPLES,
            );

            $issues[] = new ConfigurationIssue(ConfigurationFinding::InvoiceArchiveFailed, [
                'count' => (string) $failed,
                'numbers' => implode(', ', array_map(strval(...), $numbers)).($failed > self::EXAMPLES ? ', …' : ''),
            ]);
        }

        $methods = $this->connection->fetchAllAssociativeIndexed(
            "SELECT id, type, jsonData, instructions FROM tl_consho_payment_method WHERE published = 1 AND type != ''",
        );
        $onOrder = new OnOrderInvoicing($this->handlers);

        $shops = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.title, s.vatId, s.country, s.deliveryCountries, s.invoiceReverseChargeNote, s.invoiceArchive,
                    s.deliveryDaysMin, s.deliveryDaysMax, s.paymentMethods, f.path AS archivePath
               FROM tl_consho_shop s
               LEFT JOIN tl_files f ON f.uuid = s.invoiceArchiveFolder AND f.type = \'folder\'
              ORDER BY s.title, s.id',
        );

        foreach ($shops as $shop) {
            $title = (string) $shop['title'];

            if ($shop['invoiceArchive']) {
                $problem = $this->archiveProblemOf(\is_string($shop['archivePath']) ? $shop['archivePath'] : '');

                if (null !== $problem) {
                    $issues[] = new ConfigurationIssue(ConfigurationFinding::InvoiceArchiveFolderUnsafe, ['shop' => $title, 'problem' => $problem], (int) $shop['id']);
                }
            }

            if ($this->canReverseCharge($shop) && '' === trim((string) ($shop['invoiceReverseChargeNote'] ?? ''))) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::ReverseChargeNoteMissing, ['shop' => $title], (int) $shop['id']);
            }

            /*
             * An invoice written at the purchase, before anything is shipped,
             * names the expected delivery period (C-8) — and has none to name
             * while the shop states no delivery time. Only then: a shop whose
             * invoices wait for the payment or the shipment knows the date.
             */
            if (
                (int) $shop['deliveryDaysMin'] < 1
                && (int) $shop['deliveryDaysMax'] < 1
                && $onOrder->issuesOnOrder(MethodSettings::selectedIds($shop['paymentMethods']), $methods)
            ) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DeliveryTimeMissing, ['shop' => $title], (int) $shop['id']);
            }
        }

        return $issues;
    }

    private function archiveProblemOf(string $path): string|null
    {
        if ('' === $path) {
            return $GLOBALS['TL_LANG']['CONSHO']['invoiceArchiveFolder']['missing'] ?? 'missing';
        }

        $public = $this->protector->publicFolderOf($path);

        return null === $public ? null : \sprintf($GLOBALS['TL_LANG']['CONSHO']['invoiceArchiveFolder']['public'] ?? '%s', $public);
    }

    /**
     * A reverse charge needs a verified VAT ID of the buyer AND a border
     * (OrderFactory::appliesReverseCharge). The shop side of that: it has a
     * VAT ID of its own, and it delivers somewhere other than home. Whether a
     * buyer will ever bring a valid VAT ID is not knowable in advance — so
     * the finding asks whether the case CAN arise, not whether it has.
     *
     * @param array<string, mixed> $shop
     */
    private function canReverseCharge(array $shop): bool
    {
        if ('' === trim((string) ($shop['vatId'] ?? ''))) {
            return false;
        }

        $home = strtoupper((string) ($shop['country'] ?? ''));

        foreach (StringUtil::deserialize($shop['deliveryCountries'] ?? null, true) as $country) {
            if (\is_string($country) && '' !== $country && strtoupper($country) !== $home) {
                return true;
            }
        }

        return false;
    }
}

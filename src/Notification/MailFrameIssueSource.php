<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * The frame of the customer mails (MailFrames) — GARAN part 2:
 *
 * - **Footer empty** (L5): neither firm nor footer, so the mails go out
 *   without saying who sends them. Whether that is owed (§ 14 UGB, § 63 GewO)
 *   is the operator's judgement; Consho only says the place is empty.
 * - **Logo too large** (L4): it travels in every mail to a customer, and
 *   heavy mails land in spam more often — the limit the GARAN label has.
 *
 * Notes, not warnings: nothing breaks, and the invoice has its own findings
 * about the sender (InvoiceSenderIncomplete).
 */
final class MailFrameIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        // Optional for the tests that do not look at the file.
        private readonly PublicFiles|null $files = null,
    ) {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, invoiceCompany, invoiceFooter, invoiceLogo FROM tl_consho_shop ORDER BY id') as $shop) {
            $context = ['shop' => (string) $shop['title']];

            if ('' === trim((string) $shop['invoiceCompany']) && '' === trim(strip_tags((string) ($shop['invoiceFooter'] ?? '')))) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::MailFooterEmpty, $context, (int) $shop['id']);
            }

            $logo = $shop['invoiceLogo'] ?? null;
            $size = null !== $this->files && \is_string($logo) && 16 === \strlen($logo) ? $this->files->size($logo) : null;

            if (null !== $size && $size > PublicFiles::MAIL_IMAGE_MAX_BYTES) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::MailLogoTooLarge, [...$context, 'size' => (string) (int) ceil($size / 1024)], (int) $shop['id']);
            }
        }

        return $issues;
    }
}

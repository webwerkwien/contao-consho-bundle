<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Articles delivered as "Datei" without a file that can be handed out (F-5):
 * none chosen, gone from disk, or in a folder that has become public.
 *
 * The release waits for them (FileDeliveryHandler), so an order goes
 * undelivered without anyone being told — the reason for a warning. Only
 * published articles: a draft without its file yet is work in progress.
 */
final class DigitalFileIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DigitalFiles $files,
    ) {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.id, a.title
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product p ON p.id = a.pid AND a.pid > 0
              WHERE a.published = 1
                AND (a.digitalDelivery = ? OR (a.digitalDelivery = '' AND p.digitalDelivery = ?))
              ORDER BY a.id",
            [FileDeliveryHandler::getType(), FileDeliveryHandler::getType()],
        );

        $missing = [];

        foreach ($rows as $row) {
            if (null === $this->files->of((int) $row['id'])) {
                $missing[] = (string) $row['title'];
            }
        }

        if ([] === $missing) {
            return [];
        }

        return [new ConfigurationIssue(ConfigurationFinding::DigitalFileMissing, ['count' => (string) \count($missing), 'products' => implode(', ', \array_slice($missing, 0, 5)).(\count($missing) > 5 ? ', …' : '')])];
    }
}

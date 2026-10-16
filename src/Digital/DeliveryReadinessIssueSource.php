<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Delivery types of modules that cannot deliver (Block Lizenz, D7).
 *
 * The same gap as DigitalFileIssueSource closes for the core's own file: the
 * release waits, the order goes undelivered, and nobody is told. Asks every
 * handler that implements DeliveryReadinessInterface.
 *
 * One finding per reason, not per article: a missing key pair would otherwise
 * be said once for every licence article, in identical words.
 */
final class DeliveryReadinessIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DeliveryHandlers $handlers,
    ) {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->handlers->keys() as $type) {
            $handler = $this->handlers->get($type);

            if (!$handler instanceof DeliveryReadinessInterface) {
                continue;
            }

            $articles = $this->articlesOf($type);

            if ([] === $articles) {
                continue;
            }

            foreach (array_unique($handler->typeProblems()) as $code) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DeliveryTypeNotReady, [
                    'count' => (string) \count($articles),
                    'reason' => $this->reason($type, $code),
                ]);
            }

            $byReason = [];

            foreach ($handler->articleProblems(array_keys($articles)) as $id => $codes) {
                // Only what was asked about: a handler must not put a title on
                // the start page that this query did not find.
                if (!isset($articles[$id])) {
                    continue;
                }

                foreach (array_unique($codes) as $code) {
                    $byReason[$code][] = $articles[$id];
                }
            }

            foreach ($byReason as $code => $titles) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DeliveryArticleNotReady, [
                    'count' => (string) \count($titles),
                    'products' => implode(', ', \array_slice($titles, 0, 5)).(\count($titles) > 5 ? ', …' : ''),
                    'reason' => $this->reason($type, (string) $code),
                ]);
            }
        }

        return $issues;
    }

    /**
     * Published articles delivered this way, a variant by its own setting or
     * by its main product's — the rule DigitalFileIssueSource uses.
     *
     * @return array<int, string> id => title
     */
    private function articlesOf(string $type): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.id, a.title
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product p ON p.id = a.pid AND a.pid > 0
              WHERE a.published = 1
                AND (a.digitalDelivery = ? OR (a.digitalDelivery = '' AND p.digitalDelivery = ?))
              ORDER BY a.id",
            [$type, $type],
        );

        $articles = [];

        foreach ($rows as $row) {
            $articles[(int) $row['id']] = (string) $row['title'];
        }

        return $articles;
    }

    /**
     * The module's sentence, or the bare code: a missing translation has to
     * look wrong rather than empty (BackendSystemMessagesListener::sentence()).
     */
    private function reason(string $type, string $code): string
    {
        $text = $GLOBALS['TL_LANG']['CONSHO']['deliveryReason'][$type][$code] ?? null;

        return \is_string($text) && '' !== $text ? $text : $type.': '.$code;
    }
}

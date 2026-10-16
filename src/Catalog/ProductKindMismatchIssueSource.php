<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * "Produkt passt nicht zu seiner Art" (Dienstleistung, DL5, spec 6.4).
 *
 * The product mask hides what the kind of the type does not use
 * (SaleModeListener). A value entered before the type changed stays in the
 * row, invisible — this names it:
 *
 *  - a delivery type, a file or keys in the pool on anything but digital;
 *  - sale by subscription on a physical good (only the main product sells,
 *    SaleModes).
 *
 * Nothing goes wrong because of it: DigitalRelease gives a service no
 * delivery, SaleModes sells a physical good once. Hence a note.
 *
 * The kind by the rule of DbalArticleShippingData — the main product's type
 * for a variant, no type at all physical. Published products only, like the
 * other catalogue findings: what is not sold cannot mislead.
 */
final class ProductKindMismatchIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.id, a.pid, a.title, a.digitalDelivery, a.digitalFile, a.saleSubscription, a.durabilityYears, a.durabilityLabel, a.durabilityNested,
                    COALESCE(tm.kind, ta.kind, 'physical') AS kind,
                    (SELECT COUNT(*) FROM tl_consho_key k WHERE k.pid = a.id) AS keyCount
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product p ON p.id = a.pid AND a.pid > 0
               LEFT JOIN tl_consho_product_type tm ON tm.id = p.productType
               LEFT JOIN tl_consho_product_type ta ON ta.id = a.productType
              WHERE a.published = 1
              ORDER BY a.id",
        );

        $lang = $GLOBALS['TL_LANG']['CONSHO']['kindMismatch'] ?? [];
        $issues = [];

        foreach ($rows as $row) {
            $kind = ProductKind::stored((string) $row['kind']);
            $fields = [];

            if (ProductKind::Digital !== $kind) {
                if ('' !== (string) $row['digitalDelivery']) {
                    $fields[] = 'delivery';
                }

                if (null !== $row['digitalFile'] && '' !== (string) $row['digitalFile']) {
                    $fields[] = 'file';
                }

                if ((int) $row['keyCount'] > 0) {
                    $fields[] = 'keys';
                }
            }

            if (!$kind->allowsSubscription() && 0 === (int) $row['pid'] && (bool) $row['saleSubscription']) {
                $fields[] = 'subscription';
            }

            // Block GARAN (GA5): a durability guarantee is a promise about
            // goods. Refused at saving — this catches a type changed after.
            if (!$kind->isPhysical() && (!GuaranteeYears::isNone(GuaranteeYears::of($row['durabilityYears'])) || null !== $row['durabilityLabel'] || null !== $row['durabilityNested'])) {
                $fields[] = 'durability';
            }

            if ([] === $fields) {
                continue;
            }

            $issues[] = new ConfigurationIssue(ConfigurationFinding::ProductKindMismatch, [
                'product' => (string) $row['title'],
                'kind' => (string) ($lang['kinds'][$kind->value] ?? $kind->value),
                'fields' => implode((string) ($lang['and'] ?? ' + '), array_map(static fn (string $field): string => (string) ($lang[$field] ?? $field), $fields)),
            ]);
        }

        return $issues;
    }
}

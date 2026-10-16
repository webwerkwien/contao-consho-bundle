<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

use Doctrine\DBAL\Connection;

/**
 * Who points at a tax class.
 *
 * Two questions, one list of referencing tables: the voter asks whether a
 * class may still be deleted, `consho:tax:diagnose` asks which references
 * point at a class that is already gone.
 *
 * ── Why a class in use must not be deleted ───────────────────────────────
 *
 * Nothing breaks loudly. `DbalArticleTaxClasses::taxClassOf()` keeps handing
 * out the dead id, `findRate()` answers null, and TaxRateNotFound travels up
 * to the two places that swallow it: `ArticlePriceResolver` returns null and
 * the product shows no price, `CartPricer` puts the line in `$unpriced`.
 * There is no log entry and no finding — the start page walks the tax classes
 * that EXIST, so a deleted one simply drops out of the loop.
 *
 * Unlike a shipping or payment method, a tax class is not referenced by an
 * order: the order freezes the RATE (spec 3.5). So this is about the
 * catalogue's present, not about history, and the damage is repairable — by
 * someone who notices it.
 *
 * ── The list of tables is not maintained by hand ─────────────────────────
 *
 * `TaxClassReferencesAreCompleteTest` derives it from the DCAs: every field
 * whose `foreignKey` names this table, minus the parent relation of the rates
 * (they are child rows and are meant to go along). A constant nobody checks
 * reads exactly like knowledge — see the review finding F7 of 2026-09-23.
 */
final class TaxClassReferences
{
    /**
     * Table => the column pointing at tl_consho_tax_class.
     */
    public const REFERENCES = [
        'tl_consho_payment_method' => 'feeTaxClass',
        'tl_consho_product' => 'taxClass',
        'tl_consho_shipping_method' => 'taxClass',

        /*
         * Abo-3. The one reference from a PURCHASE, and the exception to "an
         * order freezes the rate": a subscription freezes the class but not
         * the rate (A9 — the rate follows the law in force), so every
         * follow-up bill looks the class up again. Deleting it would leave
         * the next bill without a rate.
         */
        'tl_consho_subscription' => 'taxClass',
    ];

    /**
     * The column that names a row in `orphans()`, where it is not `title`.
     */
    private const LABELS = ['tl_consho_subscription' => 'subscriptionNumber'];

    /**
     * Rows that still need their class (Abo-6, P51): an ended subscription is
     * never billed again, so it neither holds the class nor is an orphan.
     */
    private const STILL_USED = ['tl_consho_subscription' => "state <> 'ended'"];

    /**
     * Tables where 0 is a legitimate "none" rather than a gap (E-1): a payment
     * method needs a fee tax class only when it charges a fee at a fixed
     * class, which most never do. There a 0 is not an orphan — a method that
     * needed one is not offered at checkout (PaymentMethod::feeTaxDecided()).
     */
    public const OPTIONAL = ['tl_consho_payment_method'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function isInUse(int $taxClassId): bool
    {
        return [] !== $this->usedBy($taxClassId, 1);
    }

    /**
     * Who still needs the class, as "table id" — for a refusal that names
     * them (CatalogReferences, CW-5).
     *
     * @return list<string>
     */
    public function usedBy(int $taxClassId, int $limit = 5): array
    {
        if ($taxClassId < 1) {
            return [];
        }

        $users = [];

        foreach (self::REFERENCES as $table => $column) {
            $rows = $this->connection->fetchFirstColumn(
                \sprintf('SELECT id FROM %s WHERE %s = ?%s ORDER BY id LIMIT %d', $table, $column, isset(self::STILL_USED[$table]) ? ' AND '.self::STILL_USED[$table] : '', $limit - \count($users)),
                [$taxClassId],
            );

            foreach ($rows as $row) {
                $users[] = $table.' '.$row;
            }
        }

        return $users;
    }

    /**
     * Rows pointing at a tax class that no longer exists.
     *
     * The voter closes the ordinary way of producing these. It cannot close
     * the others: a product deleted before the class and restored from the
     * undo bin afterwards, an import, or a write straight to the database.
     *
     * Whole installation, not one shop — a dangling reference is dangling
     * whichever shop the product belongs to, and a shipping method has no
     * shop of its own.
     *
     * ⚠️ **`taxClass = 0` counts too**, although it points at nothing rather
     * than at something gone. The first draft left it out, reasoning that a
     * fresh catalogue would otherwise fill this section with noise. Measured,
     * that reasoning was wrong twice over: the field is mandatory in both
     * masks, so a row saved through the back end can never be 0 — a 0 comes
     * from an import, from raw SQL or from an installation older than the
     * field, which is exactly this section's subject. And a variant carries
     * its OWN class rather than inheriting one (`DbalArticleTaxClasses` reads
     * the row's own column; c5 shows variants with pid 1 and taxClass 2), so
     * there is no legitimate 0 to make noise with.
     *
     * The damage is the same either way — `findRate()` answers null for 0 as
     * for 99 — and for a shipping method it is worse: `DbalShippingMethodRepository`
     * filters `taxClass > 0`, so the method drops out of the choice in silence.
     *
     * @return list<array{table: string, id: int, title: string, taxClass: int}>
     */
    public function orphans(): array
    {
        $orphans = [];

        foreach (self::REFERENCES as $table => $column) {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT r.id, r.%4$s AS title, r.%1$s AS taxClass FROM %2$s r '
                    .'LEFT JOIN tl_consho_tax_class t ON t.id = r.%1$s '
                    .'WHERE t.id IS NULL%3$s ORDER BY r.id',
                    $column,
                    $table,
                    (\in_array($table, self::OPTIONAL, true) ? \sprintf(' AND r.%s > 0', $column) : '')
                    .(isset(self::STILL_USED[$table]) ? ' AND r.'.self::STILL_USED[$table] : ''),
                    self::LABELS[$table] ?? 'title',
                ),
            );

            foreach ($rows as $row) {
                $orphans[] = [
                    'table' => $table,
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'taxClass' => (int) $row['taxClass'],
                ];
            }
        }

        return $orphans;
    }
}

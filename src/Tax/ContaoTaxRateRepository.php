<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

final class ContaoTaxRateRepository implements TaxRateRepositoryInterface, ResetInterface
{
    /*
     * NULL means "no boundary" — the columns are NULL-able precisely so that
     * an open-ended rate is empty rather than 01.01.1970.
     *
     * Both columns hold a DATE, stored by the back end as midnight of that day.
     * validFrom is inclusive from that midnight on. validTo includes the WHOLE
     * day, as the statute reads: "16 % until 31.12.2020" covers 31.12. up to
     * 23:59:59. So it is compared with the start of the moment's own day, not
     * with the moment itself — `validTo >= :ts` ended every rate at 00:00 on
     * its last day, and two consecutive rates left a day without any rate.
     *
     * Deliberately NOT the start/stop rule prices use (date and time, to
     * exclusive): a tax rate changes at the start of a day, never at 18:00,
     * and the field should take the date exactly as the law states it.
     *
     * findClassesWithBoundaryIn() states the end of this rule in PHP
     * (startOfNextDay()) — change both together.
     */
    private const VALID_AT = '(validFrom IS NULL OR validFrom <= :ts) AND (validTo IS NULL OR validTo >= :dayStart)';

    /** @var array<int, true>|null the classes marked "nicht steuerbar", per request */
    private array|null $notTaxable = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findRate(int $taxClassId, string $countryCode, int $timestamp): ?string
    {
        /*
         * A class the operator marked "nicht steuerbar" (block Wertgutscheine,
         * WG3): the sale of a multi-purpose voucher is not a taxable supply
         * (Art. 30b Abs. 2 MwStSystRL, § 3 Abs. 15 UStG, UStR Rz 4) — no rate
         * in any country, at any time. 0 here keeps every price path as it is
         * (gross = net), rather than a special case in each of them.
         */
        if ($this->isNotTaxable($taxClassId)) {
            return '0.0000';
        }

        /*
         * `ORDER BY validFrom DESC` carries weight. If two rates overlap, the
         * later one wins. Without the ordering the insertion order would
         * decide, and the mistake would be invisible — both rates look valid.
         * MySQL sorts NULL last under DESC, which is exactly right here: a
         * dated rate beats the open-ended one it was created to supersede.
         *
         * Two rates with the same start are refused on save
         * (TaxRateDuplicateListener), but undo and restoring a version bypass
         * that — `id DESC` lets the later one win there too, not chance. A
         * draft (tstamp 0, an unsaved copy) is no rate.
         */
        $rate = $this->connection->fetchOne(
            'SELECT rate FROM tl_consho_tax_rate
             WHERE pid = :pid AND country = :country AND tstamp > 0
               AND '.self::VALID_AT.'
             ORDER BY validFrom DESC, id DESC
             LIMIT 1',
            ['pid' => $taxClassId, 'country' => $countryCode] + self::moment($timestamp),
        );

        return false === $rate ? null : (string) $rate;
    }

    public function findConfiguredCountries(int $taxClassId, int $timestamp): array
    {
        /** @var list<string> $codes */
        $codes = $this->connection->fetchFirstColumn(
            // A draft is no rate, as in findRate().
            'SELECT DISTINCT country FROM tl_consho_tax_rate
             WHERE pid = :pid AND tstamp > 0
               AND '.self::VALID_AT,
            ['pid' => $taxClassId] + self::moment($timestamp),
        );

        return $codes;
    }

    public function findTaxClassIdsUsedByShop(int $shopId): array
    {
        /*
         * The WHERE spells out the visibility rule from section 3.7 of the
         * specification, in the same two cases the prose has: a row counts if
         * it is assigned to this shop itself, or if it is a variant that has
         * made no choice of its own and whose main product is assigned here.
         *
         * A variant with its own selection is NOT read against its parent —
         * choosing narrows, and the narrowing has to be able to exclude a shop.
         *
         * Open question, deliberately left as the plan has it: this counts
         * every matching product, published or not. An unpublished product
         * with an exotic tax class can therefore narrow the shop's delivery
         * countries without appearing anywhere. Filtering on `published` would
         * also have to honour start/stop, so it is a decision about the
         * business rule, not a one-line fix.
         */
        /** @var list<int> $ids */
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            // A "nicht steuerbar" class has no rates and needs none (WG3) —
            // counted here, it would narrow the shop to no country at all.
            'SELECT DISTINCT p.taxClass
             FROM tl_consho_product p
             WHERE p.taxClass > 0
               AND NOT EXISTS (SELECT 1 FROM tl_consho_tax_class c WHERE c.id = p.taxClass AND c.notTaxable = 1)
               AND (
                     EXISTS (
                       SELECT 1 FROM tl_consho_product_shop s
                       WHERE s.pid = p.id AND s.shop = :shop
                     )
                  OR (
                       p.pid > 0
                       AND NOT EXISTS (
                         SELECT 1 FROM tl_consho_product_shop s WHERE s.pid = p.id
                       )
                       AND EXISTS (
                         SELECT 1 FROM tl_consho_product_shop s
                         WHERE s.pid = p.pid AND s.shop = :shop
                       )
                     )
                   )',
            ['shop' => $shopId],
        ));

        return $ids;
    }

    public function findClassesWithBoundaryIn(int $after, int $until): array
    {
        /*
         * Candidates in SQL, the end of the last day in PHP. validTo holds the
         * start of the last valid day, and the rate ends at the start of the
         * NEXT day — 23 or 25 hours later around a clock change, so not
         * validTo + 86400 (see VALID_AT). The next midnight lies at most 25
         * hours after validTo (a day of the autumn clock change), so one day
         * of slack on the lower bound would miss it; two are safe.
         */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pid, validFrom, validTo FROM tl_consho_tax_rate
             WHERE (validFrom IS NOT NULL AND validFrom > :after AND validFrom <= :until)
                OR (validTo IS NOT NULL AND validTo > :lower AND validTo <= :until)',
            ['after' => $after, 'until' => $until, 'lower' => $after - 2 * 86400],
        );

        $classes = [];

        foreach ($rows as $row) {
            $begins = null !== $row['validFrom'] && (int) $row['validFrom'] > $after && (int) $row['validFrom'] <= $until;
            $end = null === $row['validTo'] ? null : self::startOfNextDay((int) $row['validTo']);

            if ($begins || (null !== $end && $end > $after && $end <= $until)) {
                $classes[(int) $row['pid']] = true;
            }
        }

        $ids = array_keys($classes);
        sort($ids);

        return $ids;
    }

    /**
     * Whether the class is marked "nicht steuerbar" (WG3). Read once per
     * request for all classes — findRate() runs for every line and every
     * article of a projection.
     */
    public function isNotTaxable(int $taxClassId): bool
    {
        $this->notTaxable ??= array_fill_keys(array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_tax_class WHERE notTaxable = 1')), true);

        return isset($this->notTaxable[$taxClassId]);
    }

    public function reset(): void
    {
        $this->notTaxable = null;
    }

    /**
     * The start of the day is taken in the default timezone because that is the
     * one Contao parsed the back end date in; Contao sets it from its config.
     * setTime() on the local date rather than subtracting seconds keeps the
     * 23- and 25-hour days of a clock change right.
     *
     * @return array{ts: int, dayStart: int}
     */
    private static function moment(int $timestamp): array
    {
        $dayStart = (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0)
            ->getTimestamp()
        ;

        return ['ts' => $timestamp, 'dayStart' => $dayStart];
    }

    /**
     * Midnight after the given day, in the timezone Contao parsed the date in —
     * the moment a rate "valid to" that day stops applying.
     */
    private static function startOfNextDay(int $timestamp): int
    {
        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0)
            ->modify('+1 day')
            ->getTimestamp()
        ;
    }
}

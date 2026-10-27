<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;

/**
 * Whether a number range may be set to a counter, prefix and type
 * (Connect Stufe 2, CW-5, I4).
 *
 * The counter stays editable — a shop migrating from another system has to
 * continue its sequence, and a new year may start a new prefix at 0
 * ("RE-2027-"). What it must not do is hand out a number that already exists:
 * an invoice number twice is the one thing the tax office looks for, and an
 * order number twice sends two payments to one reference.
 *
 * Refused is therefore a counter below the highest number already issued
 * under the same prefix in this shop and type. Not just "the next number is
 * free": order numbers have gaps by design (NumberRangeAllocator hands one out
 * before the order completes), and a counter set into a gap would collide
 * a few orders later.
 *
 * A number counts under a prefix when it starts with it and the rest is
 * digits only — "RE-2027-00001" is not under "RE-". Mask and commands
 * call it the next free number.
 *
 * The four document types are one space of numbers (CW-6): a credit note given the prefix "RE-" at 0 would print RE-00001 next
 * to the invoice RE-00001, and a credit note is an invoice for VAT. Order
 * numbers stay a sequence of their own.
 *
 * Not closed: the moment between this check and the save. An order placed in
 * those milliseconds draws a number the check did not see. Closing it would
 * mean locking the range row across the back end's save, which DC_Table does
 * not do; the counter is changed a few times in a shop's life.
 */
final class NumberRangeChange
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return string|null the highest issued number above $current, or null when the counter is safe
     */
    public function collision(int $shopId, string $type, string $prefix, int $current): ?string
    {
        $lowerPrefix = mb_strtolower($prefix);

        /*
         * Every number of the shop, decided here, not prefiltered with LIKE.
         * A prefilter is only as good as the database's idea of case: SQLite
         * lowers ASCII only, MySQL follows its collation, PHP follows Unicode
         * — and MySQL reads "\" in a pattern as an escape. Any of them could
         * drop a real number, and a dropped number is issued twice (CW-6
         * review, K4). The counter is changed a few times in a shop's life;
         * reading its numbers then is affordable.
         */
        $numbers = 'order' === $type
            ? $this->connection->fetchFirstColumn('SELECT orderNumber FROM tl_consho_order WHERE shop = ?', [$shopId])
            : $this->connection->fetchFirstColumn('SELECT documentNumber FROM tl_consho_document WHERE shop = ?', [$shopId]);

        $highest = null;
        $highestCounter = $current;

        foreach ($numbers as $number) {
            $number = (string) $number;
            $lowerNumber = mb_strtolower($number);

            // Case does not tell two numbers apart on paper — "re-" and "RE-"
            // are one sequence, as in NumberPrefixIssueSource. The rest is
            // cut from the lowered form: lowering may change a character's
            // length in bytes ("İ" → "i̇"), and the digits do not change.
            $rest = substr($lowerNumber, \strlen($lowerPrefix));

            if (!str_starts_with($lowerNumber, $lowerPrefix) || !ctype_digit($rest)) {
                continue;
            }

            if ((int) $rest > $highestCounter) {
                $highestCounter = (int) $rest;
                $highest = $number;
            }
        }

        return $highest;
    }

    /**
     * The other document range of this shop that already uses the prefix,
     * or null. collision() looks at the numbers issued so far; two ranges on
     * one prefix collide later however the counters stand today — invoices at
     * 3 and credit notes started at 50 meet at 51. Case does not separate
     * prefixes, as above. Order numbers are a space of their own.
     *
     * @return string|null the type of the range that has the prefix
     */
    public function sharedPrefix(int $shopId, int $rangeId, string $type, string $prefix): ?string
    {
        if ('order' === $type) {
            return null;
        }

        // A shop has at most one range per type; compared here, not by the
        // database's LOWER(), for the same reason as in collision().
        $others = $this->connection->fetchAllAssociative(
            "SELECT type, prefix FROM tl_consho_number_range WHERE pid = ? AND id != ? AND type != 'order'",
            [$shopId, $rangeId],
        );

        // Trimmed like NumberRangeSetup, which avoids shared prefixes the same way.
        foreach ($others as $other) {
            if (mb_strtolower(trim((string) $other['prefix'])) === mb_strtolower(trim($prefix))) {
                return (string) $other['type'];
            }
        }

        return null;
    }
}

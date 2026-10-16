<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Whether articles are sold outside the scope of VAT (block Wertgutscheine,
 * acceptance WG-7): their tax class is marked "nicht steuerbar".
 *
 * Asked where a price carries its tax sentence. "inkl. USt." under a
 * multi-purpose voucher says something untrue — there is no tax in it — so
 * the sentence goes where EVERYTHING shown is not taxable. A basket with a
 * voucher and a book keeps it: it is true for the book.
 *
 * A variant without a class of its own takes the main product's.
 */
class NotTaxableArticles
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoTaxRateRepository $rates,
    ) {
    }

    /**
     * Whether every one of these articles is not taxable — false for none,
     * and false where one is unknown.
     *
     * @param list<int> $articleIds
     */
    public function all(array $articleIds): bool
    {
        $each = $this->each($articleIds);

        return [] !== $each && \count($each) === \count(array_unique($articleIds)) && !\in_array(false, $each, true);
    }

    /**
     * @param list<int> $articleIds
     *
     * @return array<int, bool> article id => not taxable, for the known ones
     */
    public function each(array $articleIds): array
    {
        $articleIds = array_values(array_unique(array_filter($articleIds, static fn (int $id): bool => $id > 0)));

        if ([] === $articleIds) {
            return [];
        }

        $each = [];

        foreach ($this->connection->fetchAllKeyValue(
            'SELECT a.id, CASE WHEN a.taxClass > 0 THEN a.taxClass ELSE COALESCE(m.taxClass, 0) END
               FROM tl_consho_product a LEFT JOIN tl_consho_product m ON m.id = a.pid AND a.pid > 0
              WHERE a.id IN (?)',
            [$articleIds],
            [ArrayParameterType::INTEGER],
        ) as $id => $class) {
            $each[(int) $id] = $this->rates->isNotTaxable((int) $class);
        }

        return $each;
    }
}

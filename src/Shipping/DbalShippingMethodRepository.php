<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Brick\Math\BigDecimal;
use Contao\StringUtil;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * The shipping methods a shop selected, published, in the selected order.
 *
 * The only place that reads tl_consho_shipping_method for the storefront: a
 * type gets what is built here and never reads a table itself (C3). Titles and
 * descriptions are in the main language; the checkout translates them (C-5).
 *
 * A selected id without a published row is skipped — a deleted or switched-off
 * method stays in the shop's blob until the shop is saved again, and that is
 * no reason to fail.
 */
final class DbalShippingMethodRepository implements ShippingMethodRepositoryInterface
{
    // type != '' and taxClass > 0: act=toggle publishes a row without running
    // the mask's mandatory checks, so a never-saved record can be 'published'
    // with an empty type and no reference tax class.
    //
    // A method rather than a constant, because one caller joins and needs the
    // columns qualified. A str_replace over a constant would do it too, right
    // up to the day someone adds a condition whose column name is a substring
    // of another — and then it would still produce valid SQL.
    private static function decimal(mixed $value): string
    {
        return is_numeric($value) ? (string) BigDecimal::of((string) $value)->toScale(4, RoundingMode::HalfUp) : '0.0000';
    }

    public static function offerable(string $alias = ''): string
    {
        $prefix = '' === $alias ? '' : $alias.'.';

        return \sprintf("%1\$spublished = 1 AND %1\$stype != '' AND %1\$staxClass > 0", $prefix);
    }

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<ShippingMethod>
     */
    public function forShop(int $shopId, string $currency, string $language = '', string $fallback = ''): array
    {
        $ids = MethodSettings::selectedIds($this->connection->fetchOne('SELECT shippingMethods FROM tl_consho_shop WHERE id = ?', [$shopId]));

        if ([] === $ids) {
            return [];
        }

        /*
         * Titles are stored in the main language and translated per site
         * (TranslatableFields). The join is the same one the catalogue uses,
         * so a method reads in the visitor's language without a second query
         * and without a second rule.
         */
        $join = new TranslationJoin('tl_consho_shipping_method', $language, $fallback, 'm');

        $rows = $this->connection->fetchAllAssociativeIndexed(
            \sprintf(
                'SELECT m.id, m.type, %s AS title, m.taxMode, m.taxClass, m.jsonData,
                        m.countries, m.maxLength, m.maxGirth, m.maxPieceWeight
                 FROM tl_consho_shipping_method m
                 %s
                 WHERE %s AND m.id IN (:ids)',
                $join->coalesce('title'),
                $join->sql(),
                self::offerable('m'),
            ),
            [...$join->params(), 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        // tstamp > 0: a row opened in the mask and never saved is no amount.
        // ORDER BY id: fetchAllKeyValue() keeps the last row per pid when two
        // rows share one (a duplicate restored from an old version) — without
        // an order that "last" is whatever the storage engine returns, which
        // is not guaranteed to be deterministic. Ordering by id makes it the
        // newest row every time.
        $nets = $this->connection->fetchAllKeyValue(
            'SELECT pid, net FROM tl_consho_shipping_price WHERE tstamp > 0 AND currency = ? AND pid IN (?) ORDER BY id',
            [$currency, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        );

        // Every row of the currency with its upper weight, for the weight
        // brackets (E-4, V2). Reserved key `rates`, like `net`.
        $rates = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT pid, maxWeight, net FROM tl_consho_shipping_price WHERE tstamp > 0 AND currency = ? AND pid IN (?) ORDER BY maxWeight, id',
            [$currency, $ids],
            [ParameterType::STRING, ArrayParameterType::INTEGER],
        ) as $rate) {
            $rates[(int) $rate['pid']][] = [
                'maxWeight' => (string) BigDecimal::of((string) $rate['maxWeight'])->toScale(4, RoundingMode::HalfUp),
                'net' => (string) BigDecimal::of((string) $rate['net'])->toScale(4, RoundingMode::HalfUp),
            ];
        }

        $methods = [];

        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue;
            }

            $row = $rows[$id];

            // Core keys last: `net` and `rates` are reserved and win over a module field of that name.
            $config = [
                ...MethodSettings::decode($row['jsonData'] ?? null),
                'net' => isset($nets[$id]) ? (string) BigDecimal::of((string) $nets[$id])->toScale(4, RoundingMode::HalfUp) : null,
                'rates' => $rates[$id] ?? [],
            ];

            $methods[] = new ShippingMethod(
                $id,
                (string) $row['type'],
                (string) $row['title'],
                ShippingTaxMode::tryFrom((string) $row['taxMode']) ?? ShippingTaxMode::Proportional,
                (int) $row['taxClass'],
                $config,
                array_values(array_map(
                    static fn (mixed $code): string => strtoupper(trim((string) $code)),
                    array_filter(StringUtil::deserialize($row['countries'] ?? null, true), is_string(...)),
                )),
                new ShippingLimits(
                    self::decimal($row['maxLength'] ?? null),
                    self::decimal($row['maxGirth'] ?? null),
                    self::decimal($row['maxPieceWeight'] ?? null),
                ),
            );
        }

        return $methods;
    }
}

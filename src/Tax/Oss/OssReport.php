<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax\Oss;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Tax\ShopVatId;

/**
 * The threshold and the quarterly return (block R, O2, R5, R7, R14).
 *
 * Threshold: the net of the counted lines per calendar year and currency.
 * Only euro counts against the 10,000 euro (R5); another currency is shown
 * beside it, not converted — Consho holds no exchange rate.
 *
 * Return, per quarter:
 *  - part A: per member state of consumption and rate, the supplies of the
 *    quarter and their corrections within it (Art. 369g Abs. 1 MwStSystRL);
 *  - part B: corrections of supplies of an EARLIER quarter, per member state
 *    and that quarter (Art. 369g Abs. 4, Art. 61 DVO 282/2011).
 * Amounts per currency; a return is in euro, converted at the ECB rate of the
 * last day of the quarter (Art. 369h) — the operator's step, the export says so.
 *
 * Quarters and years are those of the installation's time zone.
 */
class OssReport
{
    public const THRESHOLD = '10000';

    public const THRESHOLD_CURRENCY = 'EUR';

    public function __construct(
        private readonly Connection $connection,
        private readonly OssLedger $ledger,
    ) {
    }

    /**
     * @return list<OssBusiness>
     */
    public function businesses(): array
    {
        $groups = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, country, vatId, taxAtDestination FROM tl_consho_shop ORDER BY id') as $shop) {
            $vatKey = ShopVatId::key((string) $shop['vatId']);
            $key = '' !== $vatKey ? $vatKey : 'shop-'.$shop['id'];
            $groups[$key] ??= ['vatId' => $vatKey, 'shops' => []];
            $groups[$key]['shops'][] = [
                'id' => (int) $shop['id'],
                'title' => (string) $shop['title'],
                'country' => strtoupper((string) $shop['country']),
                'taxAtDestination' => (bool) $shop['taxAtDestination'],
            ];
        }

        $businesses = [];

        foreach ($groups as $key => $group) {
            $businesses[] = new OssBusiness((string) $key, $group['vatId'], $group['shops']);
        }

        return $businesses;
    }

    public function business(string $key): OssBusiness|null
    {
        foreach ($this->businesses() as $business) {
            if ($business->key === $key) {
                return $business;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> currency => net of the counted lines, at the currency's places
     */
    public function threshold(OssBusiness $business, int $year): array
    {
        $sums = [];

        foreach ($this->ledger->entries($business->shopIds(), self::yearStart($year), self::yearStart($year + 1)) as $entry) {
            if ($entry->threshold) {
                $sums[$entry->currency] = BigDecimal::of($sums[$entry->currency] ?? '0')->plus($entry->net);
            }
        }

        ksort($sums);
        $result = [];

        foreach ($sums as $currency => $sum) {
            $result[(string) $currency] = self::money($sum, (string) $currency);
        }

        return $result;
    }

    /**
     * The share of the threshold the euro sum reaches, in whole percent.
     *
     * @param array<string, string> $sums
     */
    public static function percentOf(array $sums): int
    {
        $euro = BigDecimal::of($sums[self::THRESHOLD_CURRENCY] ?? '0');

        return $euro->isPositive() ? (int) (string) $euro->multipliedBy(100)->dividedBy(self::THRESHOLD, 0, RoundingMode::Down) : 0;
    }

    /**
     * @return array{a: list<array{country: string, rate: string, currency: string, net: string, tax: string}>, b: list<array{country: string, period: string, currency: string, net: string, tax: string}>, entries: list<OssEntry>}
     */
    public function quarter(OssBusiness $business, int $year, int $quarter): array
    {
        $from = self::quarterStart($year, $quarter);
        $until = 4 === $quarter ? self::yearStart($year + 1) : self::quarterStart($year, $quarter + 1);
        $entries = array_values(array_filter(
            $this->ledger->entries($business->shopIds(), $from, $until),
            static fn (OssEntry $entry): bool => $entry->oss,
        ));

        $a = [];
        $b = [];

        foreach ($entries as $entry) {
            if ($entry->supplyAt >= $from) {
                $key = $entry->taxCountry.'|'.$entry->taxRate.'|'.$entry->currency;
                $a[$key] ??= ['country' => $entry->taxCountry, 'rate' => $entry->taxRate, 'currency' => $entry->currency, 'net' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
                $a[$key]['net'] = $a[$key]['net']->plus($entry->net);
                $a[$key]['tax'] = $a[$key]['tax']->plus($entry->tax);
            } else {
                $period = self::periodOf($entry->supplyAt);
                $key = $entry->taxCountry.'|'.$period.'|'.$entry->currency;
                $b[$key] ??= ['country' => $entry->taxCountry, 'period' => $period, 'currency' => $entry->currency, 'net' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];
                $b[$key]['net'] = $b[$key]['net']->plus($entry->net);
                $b[$key]['tax'] = $b[$key]['tax']->plus($entry->tax);
            }
        }

        ksort($a);
        ksort($b);

        return [
            'a' => array_values(array_map(static fn (array $row): array => array_merge($row, ['net' => self::money($row['net'], $row['currency']), 'tax' => self::money($row['tax'], $row['currency'])]), $a)),
            'b' => array_values(array_map(static fn (array $row): array => array_merge($row, ['net' => self::money($row['net'], $row['currency']), 'tax' => self::money($row['tax'], $row['currency'])]), $b)),
            'entries' => $entries,
        ];
    }

    /**
     * "2026-Q3".
     */
    public static function periodOf(int $timestamp): string
    {
        return date('Y', $timestamp).'-Q'.(intdiv((int) date('n', $timestamp) - 1, 3) + 1);
    }

    public static function yearStart(int $year): int
    {
        return (int) mktime(0, 0, 0, 1, 1, $year);
    }

    public static function quarterStart(int $year, int $quarter): int
    {
        return (int) mktime(0, 0, 0, 3 * ($quarter - 1) + 1, 1, $year);
    }

    private static function money(BigDecimal $amount, string $currency): string
    {
        return (string) $amount->toScale(self::digits($currency), RoundingMode::HalfUp);
    }

    private static function digits(string $currency): int
    {
        return Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
    }
}

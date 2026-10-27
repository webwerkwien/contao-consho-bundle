<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssBusiness;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssEntry;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssReport;

/**
 * The page "Steuermeldung" (block R, R6, R7): per business the OSS threshold
 * this year and last, and the quarterly export. A view of the dashboard since
 * DB6, reached from its section "Steuermeldung".
 *
 * Only reads. The module check is Contao's. Everything comes from the query
 * string, so an export is a link: `business`, `year`, `quarter`, `export`
 * (`summary` or `records`).
 *
 * The CSV is neutral (R7), no authority's upload format: semicolons, a byte
 * order mark for Excel, decimal commas to go with the semicolons.
 */
#[Autoconfigure(public: true)]
final class TaxReportBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_tax_report.html.twig';

    /**
     * Since the dashboard (DB6) a view of it, not a menu item of its own:
     * DashboardModule hands over on `view=taxReport`. Not a `key` — Contao
     * runs a module's `callback` before it looks at `key` (Backend.php:315).
     */
    public const ROUTE = ['do' => 'consho_dashboard', 'view' => 'taxReport'];

    /** Quarters offered for export, the current one included. */
    private const QUARTERS = 8;

    public function __construct(
        private readonly OssReport $report,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoFramework $framework,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
        private readonly Security|null $security = null,
    ) {
    }

    public function __invoke(): string
    {
        // Contao checks `consho_dashboard` only. The report and its record
        // export are order data, bound like the dashboard's figures (DB8) —
        // the dashboard alone was not enough before DB6 either.
        if (null !== $this->security && !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'consho_order')) {
            throw new AccessDeniedHttpException();
        }

        $this->framework->initialize();

        $query = $this->requests->getCurrentRequest()?->query;
        $lang = $GLOBALS['TL_LANG']['CONSHO']['taxReport'] ?? [];
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $now = time();
        $year = (int) date('Y', $now);

        $export = (string) $query?->get('export', '');

        if ('' !== $export) {
            $business = $this->report->business((string) $query?->get('business', ''));
            $period = $this->period((int) $query?->get('year', 0), (int) $query?->get('quarter', 0), $now);

            if (null !== $business && null !== $period) {
                $this->download($business, $period[0], $period[1], 'records' === $export ? 'records' : 'summary', $lang);
            }
        }

        $businesses = [];

        foreach ($this->report->businesses() as $business) {
            $years = [];

            foreach ([$year, $year - 1] as $checked) {
                $sums = $this->report->threshold($business, $checked);
                $years[] = [
                    'year' => $checked,
                    'percent' => OssReport::percentOf($sums),
                    'sums' => array_map(
                        fn (string $currency, string $amount): array => ['amount' => $this->money->format($amount, $currency, $language), 'counts' => OssReport::THRESHOLD_CURRENCY === $currency],
                        array_keys($sums),
                        array_values($sums),
                    ),
                ];
            }

            $businesses[] = [
                'label' => $business->label(),
                // Without a VAT ID the label already is the shop's title
                // (R-5: "Shop (DE) Shop (DE)").
                'shops' => '' === $business->vatId ? '' : implode(', ', array_map(static fn (array $shop): string => $shop['title'], $business->shops)),
                'atDestination' => $business->taxesAtDestination(),
                'years' => $years,
                'quarters' => array_map(fn (array $q): array => [
                    'label' => $q[0].'-Q'.$q[1],
                    'summary' => $this->router->generate('contao_backend', [...self::ROUTE, 'business' => $business->key, 'year' => $q[0], 'quarter' => $q[1], 'export' => 'summary']),
                    'records' => $this->router->generate('contao_backend', [...self::ROUTE, 'business' => $business->key, 'year' => $q[0], 'quarter' => $q[1], 'export' => 'records']),
                ], $this->quarters($now)),
            ];
        }

        return $this->twig->render(self::TEMPLATE, [
            'lang' => $lang,
            'back' => $this->router->generate('contao_backend', ['do' => self::ROUTE['do']]),
            'threshold' => $this->money->format(OssReport::THRESHOLD, OssReport::THRESHOLD_CURRENCY, $language),
            'businesses' => $businesses,
        ]);
    }

    /**
     * @return list<array{0: int, 1: int}> year and quarter, newest first
     */
    private function quarters(int $now): array
    {
        $year = (int) date('Y', $now);
        $quarter = intdiv((int) date('n', $now) - 1, 3) + 1;
        $quarters = [];

        for ($i = 0; $i < self::QUARTERS; ++$i) {
            $quarters[] = [$year, $quarter];

            if (0 === --$quarter) {
                $quarter = 4;
                --$year;
            }
        }

        return $quarters;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function period(int $year, int $quarter, int $now): array|null
    {
        foreach ($this->quarters($now) as $offered) {
            if ($offered === [$year, $quarter]) {
                return $offered;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $lang
     */
    private function download(OssBusiness $business, int $year, int $quarter, string $kind, array $lang): never
    {
        $result = $this->report->quarter($business, $year, $quarter);
        $rows = 'records' === $kind ? self::recordRows($result['entries'], $lang) : self::summaryRows($result, $lang);
        $fileName = strtolower(\sprintf('oss-%s-%d-q%d%s.csv', preg_replace('/[^A-Za-z0-9]+/', '-', $business->label()) ?? 'shop', $year, $quarter, 'records' === $kind ? '-belege' : ''));

        throw new ResponseException(new Response(self::csv($rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName),
            'Cache-Control' => 'private, no-store',
        ]));
    }

    /**
     * @param array{a: list<array{country: string, rate: string, currency: string, net: string, tax: string}>, b: list<array{country: string, period: string, currency: string, net: string, tax: string}>, entries: list<OssEntry>} $result
     * @param array<string, string>                                                                                                                                                                                             $lang
     *
     * @return list<list<string>>
     *
     * @internal public for the test only
     */
    public static function summaryRows(array $result, array $lang): array
    {
        $rows = [[
            $lang['colPart'] ?? 'Teil',
            $lang['colCountry'] ?? 'Mitgliedstaat',
            $lang['colRate'] ?? 'Satz',
            $lang['colPeriod'] ?? 'Ursprünglicher Zeitraum',
            $lang['colCurrency'] ?? 'Währung',
            $lang['colNet'] ?? 'Bemessungsgrundlage',
            $lang['colTax'] ?? 'Steuer',
        ]];

        foreach ($result['a'] as $row) {
            $rows[] = ['A', $row['country'], self::number($row['rate']), '', $row['currency'], self::number($row['net']), self::number($row['tax'])];
        }

        foreach ($result['b'] as $row) {
            $rows[] = ['B', $row['country'], '', $row['period'], $row['currency'], self::number($row['net']), self::number($row['tax'])];
        }

        return $rows;
    }

    /**
     * @param list<OssEntry>        $entries
     * @param array<string, string> $lang
     *
     * @return list<list<string>>
     *
     * @internal public for the test only
     */
    public static function recordRows(array $entries, array $lang): array
    {
        $date = static fn (int|null $at): string => null === $at || 0 === $at ? '' : date('Y-m-d', $at);
        $rows = [[
            $lang['colOrder'] ?? 'Bestellung',
            $lang['colDocument'] ?? 'Beleg',
            $lang['colKind'] ?? 'Art',
            $lang['colDate'] ?? 'Datum',
            $lang['colSupply'] ?? 'Leistung zum',
            $lang['colPaid'] ?? 'Bezahlt am',
            $lang['colDelivered'] ?? 'Geliefert am',
            $lang['colDestination'] ?? 'Bestimmungsland',
            $lang['colCountry'] ?? 'Mitgliedstaat',
            $lang['colRate'] ?? 'Satz',
            $lang['colCurrency'] ?? 'Währung',
            $lang['colNet'] ?? 'Bemessungsgrundlage',
            $lang['colTax'] ?? 'Steuer',
            $lang['colItem'] ?? 'Position',
        ]];

        foreach ($entries as $entry) {
            $rows[] = [
                $entry->orderNumber,
                $entry->document,
                $entry->correction ? ($lang['correction'] ?? 'Berichtigung') : ($lang['supply'] ?? 'Umsatz'),
                $date($entry->at),
                $date($entry->supplyAt),
                $date($entry->paidAt),
                $date($entry->deliveredAt),
                $entry->destinationCountry,
                $entry->taxCountry,
                self::number($entry->taxRate),
                $entry->currency,
                self::number($entry->net),
                self::number($entry->tax),
                $entry->description,
            ];
        }

        return $rows;
    }

    /**
     * @param list<list<string>> $rows
     *
     * @internal public for the test only
     */
    public static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        \assert(false !== $handle);

        foreach ($rows as $row) {
            // Text a person typed may start like a formula; amounts are numbers and stay as they are.
            fputcsv($handle, array_map(static fn (string $cell): string => is_numeric(str_replace(',', '.', $cell)) ? $cell : PurchaseBackendController::csvCell($cell), $row), ';', '"', '');
        }

        rewind($handle);

        // With a byte order mark: Excel opens UTF-8 only with it.
        return "\u{FEFF}".stream_get_contents($handle);
    }

    /**
     * A decimal comma, the way a spreadsheet with semicolons reads it; the
     * places as stored.
     */
    private static function number(string $amount): string
    {
        return str_replace('.', ',', (string) BigDecimal::of('' !== $amount ? $amount : '0'));
    }
}

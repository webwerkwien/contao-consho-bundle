<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Dashboard\OpenWork;
use Webwerkwien\ContaoConshoBundle\Dashboard\SalesFigures;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssReport;
use Webwerkwien\ContaoConshoBundle\EventListener\BackendSystemMessagesListener;
use Webwerkwien\ContaoConshoBundle\Dashboard\OpenWorkReader;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;
use Webwerkwien\ContaoConshoBundle\Stock\StockIssueSource;

/**
 * The menu item "Dashboard" (block Dashboard): the first page of the group
 * "Consho" (DB1).
 *
 * Only reads. Every tile names the module it links to, and appears only for a
 * user who may open that module (DB8) — whoever sees no orders sees no order
 * figures either.
 */
#[Autoconfigure(public: true)]
final class DashboardBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_dashboard.html.twig';

    public const FILTER = 'consho_filter';

    public const SHOP = 'consho_shop';

    public const STOCK_ITEMS = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly OpenWorkReader $work,
        private readonly StockIssueSource $stock,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoFramework $framework,
        private readonly BackendSystemMessagesListener $findings,
        private readonly SalesFigures $sales,
        private readonly OssReport $taxReport,
        private readonly SetupGuide $guide,
        private readonly Security|null $security = null,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    public function __invoke(): string
    {
        $this->framework->initialize();

        $now = time();
        $lang = $GLOBALS['TL_LANG']['CONSHO']['dashboard'] ?? [];
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $titles = $this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_shop ORDER BY id');

        // Before the findings are read for the status line: a click on "Zur
        // Kenntnis genommen" is handled in there and redirects.
        $summary = $this->findings->summary();
        $issues = $this->findings->issues();

        // DB8: the figures are order data.
        $sales = $this->may('consho_order') ? $this->sales->byShop($now) : [];

        // Order data like the figures (DB8).
        $tax = $this->may('consho_order') ? $this->tax($now) : [];

        $shops = [];

        foreach ($this->work->byShop($now) as $shopId => $work) {
            $ready = $this->guide->forShop($shopId, (string) ($titles[$shopId] ?? ''), $issues, $now)->ready;
            $stopping = SetupGuide::stopping($shopId, $issues);

            $shops[] = [
                'title' => (string) ($titles[$shopId] ?? ''),
                'figures' => $this->figures($sales[$shopId] ?? [], $language),
                'tax' => $tax[$shopId] ?? null,
                // DB9: the dashboard says that a shop cannot sell; since
                // block I the line leads to the setup page, and "cannot sell"
                // is that page's "not ready" — the start point and its
                // currency included (I: three definitions before).
                'incomplete' => !$ready,
                // H2: the status stays for everyone, the reasons only for who
                // may put them right — then the line says so. The reasons are
                // the same findings "ready" counts (I);
                // a missing start point has no finding, the setup page says it.
                'reasonsHidden' => !$ready && [] !== $stopping && [] === array_filter($stopping, $this->findings->maySee(...)),
                'tiles' => $this->tiles($shopId, $work, $lang, $language),
            ];
        }

        $stock = null;

        if ($this->may('consho_stock')) {
            // What to reorder, by name and quantity left — the first ten, the
            // rest behind the filtered list.
            $low = $this->stock->low();
            $stock = [] === $low ? null : [
                'count' => \count($low),
                'items' => \array_slice(array_values($low), 0, self::STOCK_ITEMS),
                'url' => $this->url('consho_stock', 'low', 0),
            ];
        }

        return $this->twig->render(self::TEMPLATE, [
            'lang' => $lang,
            'shops' => $shops,
            // DB7: one shop needs no heading.
            'showShopTitles' => \count($shops) > 1,
            'stock' => $stock,
            // Two counting boxes at the top, each list in a dialog (DB1–DB3).
            // The HTML is built by the listener, which escapes every value it
            // inserts.
            'findings' => $summary,
            'figuresShown' => $this->may('consho_order'),
            'taxUrl' => $this->router->generate('contao_backend', TaxReportBackendController::ROUTE),
            // Block I (I5): "Einrichtung fortsetzen" at an unfinished shop, and
            // the way in when there is no shop configuration at all — then
            // the loop above has nothing to show.
            'setupUrl' => $this->router->generate('contao_backend', SetupBackendController::ROUTE),
            'noShop' => [] === $titles,
        ]);
    }

    /**
     * @param array<string, array<string, array{orders: int, total: string}>> $byCurrency
     *
     * @return list<array{currency: string, periods: list<array{orders: int, total: string}>}>
     */
    private function figures(array $byCurrency, string $language): array
    {
        $rows = [];

        foreach ($byCurrency as $currency => $periods) {
            $rows[] = [
                'currency' => $currency,
                'periods' => array_map(
                    fn (string $period): array => [
                        'orders' => $periods[$period]['orders'],
                        'total' => $this->money->format($periods[$period]['total'], $currency, $language),
                    ],
                    [SalesFigures::TODAY, SalesFigures::WEEK, SalesFigures::MONTH],
                ),
            ];
        }

        return $rows;
    }

    /**
     * DB6: the threshold, this year and last, at every shop of a business —
     * shops with one VAT ID count against one threshold, so each of them
     * names the others. The page itself is one click away.
     *
     * @return array<int, array{label: string, shared: string, thisYear: int, lastYear: int, thisLevel: string, lastLevel: string}>
     */
    private function tax(int $now): array
    {
        $year = (int) date('Y', $now);
        $byShop = [];

        foreach ($this->taxReport->businesses() as $business) {
            $thisYear = OssReport::percentOf($this->taxReport->threshold($business, $year));
            $lastYear = OssReport::percentOf($this->taxReport->threshold($business, $year - 1));

            foreach ($business->shops as $shop) {
                $others = array_filter($business->shops, static fn (array $other): bool => $other['id'] !== $shop['id']);

                $byShop[$shop['id']] = [
                    'label' => $business->vatId,
                    'shared' => implode(', ', array_map(static fn (array $other): string => $other['title'], $others)),
                    'thisYear' => $thisYear,
                    'lastYear' => $lastYear,
                    'thisLevel' => self::level($thisYear),
                    'lastLevel' => self::level($lastYear),
                ];
            }
        }

        return $byShop;
    }

    /**
     * From 90 % the threshold is close, from 100 % it is crossed — then the
     * tax of the destination country applies: orange text from 90 %, red and
     * bold from 100 %.
     */
    public static function level(int $percent): string
    {
        return match (true) {
            $percent >= 100 => 'over',
            $percent >= 90 => 'near',
            default => '',
        };
    }

    /**
     * The four tiles stand always, so the grid does not jump; a 0 is grey and
     * links nowhere (D1). Complaints are rare and appear
     * only when there is one (D3). A tile of a module the user may not open
     * is left out (DB8, D4).
     *
     * @param array<string, string> $lang
     *
     * @return list<array{label: string, count: int, url: string, note: string, noteUrl: string}>
     */
    private function tiles(int $shop, OpenWork $work, array $lang, string $language): array
    {
        $tiles = [];

        if ($this->may('consho_order')) {
            $tiles[] = $this->tile($lang['unpaid'] ?? 'Zahlungen', $work->unpaid, $this->url('consho_order', OpenWorkReader::UNPAID, $shop),
                $work->overdue > 0 ? \sprintf($lang['overdue'] ?? 'davon %d überfällig', $work->overdue) : '',
                $work->overdue > 0 ? $this->url('consho_order', OpenWorkReader::OVERDUE, $shop) : '',
            );
            $tiles[] = $this->tile($lang['toDeliver'] ?? 'Lieferungen', $work->toDeliver, $this->url('consho_order', OpenWorkReader::TO_DELIVER, $shop));
        }

        if ($this->may('consho_withdrawal')) {
            $tiles[] = $this->tile($lang['undecided'] ?? 'Widerrufe', $work->undecided, $this->url('consho_withdrawal', OpenWorkReader::UNDECIDED, $shop), $this->due($work->undecidedDue, $lang, $language));
            $tiles[] = $this->tile($lang['refundOpen'] ?? 'Erstattungen', $work->refundOpen, $this->url('consho_withdrawal', OpenWorkReader::REFUND_OPEN, $shop), $this->due($work->refundDue, $lang, $language));
        }

        if ($this->may('consho_order') && $work->complaints > 0) {
            $tiles[] = $this->tile($lang['complaints'] ?? 'Reklamationen', $work->complaints, $this->url('consho_order', OpenWorkReader::COMPLAINT, $shop));
        }

        return $tiles;
    }

    /**
     * @return array{label: string, count: int, url: string, note: string, noteUrl: string}
     */
    private function tile(string $label, int $count, string $url, string $note = '', string $noteUrl = ''): array
    {
        // D1: a 0 has nothing to open, and no deadline to name.
        return 0 === $count
            ? ['label' => $label, 'count' => 0, 'url' => '', 'note' => '', 'noteUrl' => '']
            : ['label' => $label, 'count' => $count, 'url' => $url, 'note' => $note, 'noteUrl' => $noteUrl];
    }

    private function url(string $module, string $filter, int $shop): string
    {
        $query = ['do' => $module, self::FILTER => $filter];

        if ($shop > 0) {
            $query[self::SHOP] = $shop;
        }

        return $this->router->generate('contao_backend', $query);
    }

    private function may(string $module): bool
    {
        return null === $this->security || $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module);
    }

    /**
     * @param array<string, mixed> $lang
     */
    private function due(int $timestamp, array $lang, string $language): string
    {
        return $timestamp > 0 ? \sprintf((string) ($lang['refundDue'] ?? 'bis %s'), $this->day($timestamp, $language)) : '';
    }

    private function day(int $timestamp, string $language): string
    {
        return (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($timestamp);
    }
}

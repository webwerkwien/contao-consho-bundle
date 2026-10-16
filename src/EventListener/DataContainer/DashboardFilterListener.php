<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\DashboardBackendController;
use Webwerkwien\ContaoConshoBundle\Dashboard\OpenWorkReader;
use Webwerkwien\ContaoConshoBundle\Stock\StockIssueSource;

/**
 * The lists behind the dashboard's links (DB4): `consho_filter` in the query
 * narrows the list to the records the tile counted.
 *
 * Contao filters a list through its session, not its URL. `root` is the one
 * way a URL can narrow it: DC_Table runs the onload callbacks (:235) before it
 * reads the roots (:251), and a list view adds `id IN (root)` to its query.
 *
 * ⚠️ An EMPTY root lists everything — DC_Table tests `!empty($this->root)`.
 * Nothing left behind a tile (someone dealt with it meanwhile) must show
 * nothing, hence [0]: no record has id 0.
 *
 * Only the list view: with `act` or `key` set, the record or the action is the
 * subject, and a root would refuse records outside it.
 */
final class DashboardFilterListener
{
    /** Filters each module accepts — another value is ignored, not trusted. */
    private const FILTERS = [
        'consho_order' => ['tl_consho_order', [OpenWorkReader::UNPAID, OpenWorkReader::OVERDUE, OpenWorkReader::TO_DELIVER, OpenWorkReader::COMPLAINT]],
        'consho_withdrawal' => ['tl_consho_withdrawal', [OpenWorkReader::UNDECIDED, OpenWorkReader::REFUND_OPEN]],
        'consho_stock' => ['tl_consho_stock', ['low']],
    ];

    public function __construct(
        private readonly RequestStack $requests,
        private readonly OpenWorkReader $work,
        private readonly StockIssueSource $stock,
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_withdrawal', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_stock', target: 'config.onload')]
    public function narrow(DataContainer $dc): void
    {
        $query = $this->requests->getCurrentRequest()?->query;

        if (null === $query || $query->has('act') || $query->has('key')) {
            return;
        }

        $module = $query->getString('do');
        $filter = $query->getString(DashboardBackendController::FILTER);
        [$table, $allowed] = self::FILTERS[$module] ?? ['', []];

        // Contao carries the query on: from a filtered order list into the
        // withdrawals of one order, `consho_filter` is still there — but that
        // list is not the one the filter means (review A6).
        if ($dc->table !== $table || !\in_array($filter, $allowed, true)) {
            return;
        }

        $ids = 'low' === $filter
            ? $this->stockRows()
            : $this->work->ids($filter, time(), $query->getInt(DashboardBackendController::SHOP));

        $GLOBALS['TL_DCA'][$table]['list']['sorting']['root'] = [] === $ids ? [0] : $ids;

        $label = $GLOBALS['TL_LANG']['CONSHO']['dashboard']['filters'][$filter] ?? $filter;

        $this->framework->getAdapter(Message::class)->addRaw(\sprintf(
            '<p class="tl_info">%s <a href="%s">%s</a></p>',
            StringUtil::specialchars(\sprintf($GLOBALS['TL_LANG']['CONSHO']['dashboard']['filtered'] ?? 'Gefiltert: %s (%d).', \is_string($label) ? $label : $filter, \count($ids))),
            StringUtil::specialchars($this->router->generate('contao_backend', ['do' => $module])),
            StringUtil::specialchars($GLOBALS['TL_LANG']['CONSHO']['dashboard']['showAll'] ?? 'Alle anzeigen'),
        ));
    }

    /**
     * @return list<int>
     */
    private function stockRows(): array
    {
        $articles = array_keys($this->stock->low());

        if ([] === $articles) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_stock WHERE article IN (?) ORDER BY id',
            [$articles],
            [ArrayParameterType::INTEGER],
        ));
    }
}

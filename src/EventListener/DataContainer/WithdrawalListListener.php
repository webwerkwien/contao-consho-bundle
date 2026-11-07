<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSignals;

/**
 * The withdrawal list (D-3, F11): under an order, and on its own in the menu
 * item "Widerrufe".
 *
 * ── One table, two lists ──────────────────────────────────────────────────
 *
 * Under an order the table is a column list of that order's withdrawals. In
 * the module `consho_withdrawal` it is listed flat over all orders, newest
 * first, with a filter by state and columns for order and customer — the same
 * records and the same page to process them, not a second table that could
 * say something different. The switch happens when the DCA is loaded, by
 * module, before DC_Table reads the configuration.
 *
 * ── The columns say what is due ───────────────────────────────────────────
 *
 * State as signals, number, receipt — and, while a withdrawal is not rejected
 * and not refunded, the day the refund is due (receipt + 14 days, § 357
 * Abs. 1 BGB, § 14 Abs. 1 FAGG). That day is why the list exists.
 */
class WithdrawalListListener
{
    public const MODULE = 'consho_withdrawal';

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly WithdrawalSignals $signals,
    ) {
    }

    /*
     * A hook, not `config.onload`: DC_Table reads `ptable` in its constructor
     * (DC_Table.php:229) BEFORE it runs the onload callbacks (:235) — removed
     * there, the parent would already be set. `loadDataContainer` runs when
     * the DCA is loaded, before any driver is built.
     */
    #[AsHook('loadDataContainer')]
    public function flatInModule(string $table): void
    {
        if ('tl_consho_withdrawal' !== $table || self::MODULE !== $this->requests->getCurrentRequest()?->query->getString('do')) {
            return;
        }

        $dca = &$GLOBALS['TL_DCA']['tl_consho_withdrawal'];

        // Over all orders: no parent to show, and no parent to require.
        unset($dca['config']['ptable']);

        $dca['list']['sorting'] = [
            'mode' => 1,
            'fields' => ['receivedAt DESC', 'id DESC'],
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'number',
        ];

        // Columns like the order list (F12); `signals`, `customer`,
        // `refundDue` and `amount` are virtual — see the DCA.
        $dca['list']['label'] = [
            'fields' => ['signals', 'number', 'pid', 'customer', 'receivedAt', 'refundDue', 'amount'],
            'showColumns' => true,
        ];

        $dca['fields']['state']['filter'] = true;
        $dca['fields']['number']['search'] = true;
    }

    /**
     * One cell per field of `list.label.fields` — in the menu item (F12) with
     * order and customer, under the order without them.
     *
     * ⚠️ An ARRAY — with showColumns Contao throws a string away and falls
     * back to its own values (see OrderLabelListener).
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_withdrawal', target: 'list.label.label')]
    public function label(array $row): array
    {
        /** @var list<string> $fields */
        $fields = $GLOBALS['TL_DCA']['tl_consho_withdrawal']['list']['label']['fields'] ?? [];
        $cells = $this->cells($row, \in_array('pid', $fields, true));

        return array_map(static fn (string $field): string => $cells[$field] ?? '', $fields);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function cells(array $row, bool $orderColumn): array
    {
        $order = $this->connection->fetchAssociative('SELECT orderNumber, currency FROM tl_consho_order WHERE id = ?', [(int) ($row['pid'] ?? 0)]);
        $received = (int) ($row['receivedAt'] ?? 0);
        $due = WithdrawalSignals::refundDue($received);
        $open = null === ($row['refundedAt'] ?? null) && 'rejected' !== ($row['state'] ?? '');

        $dueCell = '';

        if ($open) {
            $dueCell = StringUtil::specialchars(BackendDate::format($due));

            // Past due: said by weight, not only by the red symbol.
            if ($due < time()) {
                $dueCell = '<strong>'.$dueCell.'</strong>';
            }
        }

        $amount = '';

        if (null !== ($row['refundAmount'] ?? null) && false !== $order) {
            $amount = trim(number_format((float) $row['refundAmount'], 2, ',', '.').' '.StringUtil::specialchars((string) $order['currency']));
        }

        $lang = $GLOBALS['TL_LANG']['tl_consho_withdrawal'] ?? [];

        // WA: no order yet (A4), or in after the window's end (B3) — said in
        // the order column, where the operator looks for the order.
        $orderCell = false === $order
            ? '<em>'.StringUtil::specialchars((string) ($lang['unassigned'] ?? 'nicht zugeordnet')).'</em>'
            : StringUtil::specialchars((string) $order['orderNumber']);
        $numberCell = StringUtil::specialchars((string) ($row['number'] ?? ''));

        // WA, B3: in the order column, where the operator looks for the
        // order; under the order, which has none, at the number.
        if ((bool) ($row['afterWindow'] ?? false)) {
            $late = ' <span class="tl_gray" title="'.StringUtil::specialchars((string) ($lang['afterWindow'][1] ?? '')).'">('.StringUtil::specialchars((string) ($lang['afterWindow'][0] ?? 'nach Fensterende')).')</span>';

            if ($orderColumn) {
                $orderCell .= $late;
            } else {
                $numberCell .= $late;
            }
        }

        $source = (string) ($row['source'] ?? '');
        $scope = (string) ($row['scope'] ?? 'all');

        return [
            'signals' => WithdrawalSignals::html($this->signals->of($row, time())),
            'number' => $numberCell,
            'pid' => $orderCell,
            'customer' => StringUtil::specialchars((string) ($row['name'] ?? '')),
            'receivedAt' => StringUtil::specialchars(BackendDate::withTime($received)),
            'source' => StringUtil::specialchars((string) ($lang['sources'][$source] ?? $source)),
            'scope' => StringUtil::specialchars((string) ($lang['scopes'][$scope] ?? $scope)),
            'refundDue' => $dueCell,
            'amount' => $amount,
        ];
    }
}

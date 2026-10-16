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
 * Under an order the table is a child list (mode 4). In the module
 * `consho_withdrawal` it is listed flat over all orders, newest first, with a
 * filter by state — the same records and the same page to process them, not a
 * second table that could say something different. The switch happens when
 * the DCA is loaded, by module, before DC_Table reads the configuration.
 *
 * ── The label says what is due ────────────────────────────────────────────
 *
 * Number, order, receipt, state — and, while a withdrawal is not rejected
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
     * One cell per column in the menu item (F12), one line under the order.
     *
     * ⚠️ With showColumns an ARRAY — Contao throws a string away there and
     * falls back to its own values (see OrderLabelListener). Under the order
     * (mode 4, no columns) the one-line label stays.
     *
     * @return string|list<string>
     */
    #[AsCallback(table: 'tl_consho_withdrawal', target: 'list.label.label')]
    public function label(array $row): string|array
    {
        if ($GLOBALS['TL_DCA']['tl_consho_withdrawal']['list']['label']['showColumns'] ?? false) {
            return $this->columns($row);
        }

        return $this->line($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function columns(array $row): array
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

        return [
            WithdrawalSignals::html($this->signals->of($row, time())),
            StringUtil::specialchars((string) ($row['number'] ?? '')),
            false === $order ? '' : StringUtil::specialchars((string) $order['orderNumber']),
            StringUtil::specialchars((string) ($row['name'] ?? '')),
            StringUtil::specialchars(BackendDate::withTime($received)),
            $dueCell,
            $amount,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function line(array $row): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_withdrawal'] ?? [];
        $received = (int) ($row['receivedAt'] ?? 0);
        $state = (string) ($row['state'] ?? 'received');

        $parts = [
            StringUtil::specialchars((string) ($row['number'] ?? '')),
            StringUtil::specialchars(BackendDate::withTime($received)),
            StringUtil::specialchars((string) ($lang['states'][$state] ?? $state)),
        ];

        if (null !== ($row['refundedAt'] ?? null)) {
            $parts[] = \sprintf((string) ($lang['labelRefunded'] ?? 'erstattet am %s'), StringUtil::specialchars(BackendDate::format((int) $row['refundedAt'])));
        } elseif ('rejected' !== $state) {
            $due = WithdrawalSignals::refundDue($received);
            $text = \sprintf((string) ($lang['labelDue'] ?? 'Erstattung fällig bis %s'), StringUtil::specialchars(BackendDate::format($due)));

            // Past due and not refunded: said, not only coloured.
            $parts[] = $due < time() ? '<strong>'.$text.'</strong>' : $text;
        }

        $order = $this->connection->fetchOne('SELECT orderNumber FROM tl_consho_order WHERE id = ?', [(int) ($row['pid'] ?? 0)]);

        if (\is_string($order) && '' !== $order) {
            array_splice($parts, 1, 0, [StringUtil::specialchars($order)]);
        }

        return implode(' — ', $parts);
    }
}

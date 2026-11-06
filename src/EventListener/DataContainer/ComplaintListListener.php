<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;
use Webwerkwien\ContaoConshoBundle\Order\StatusColour;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The complaint list (block Reklamation, R10): under an order, and on its own
 * in the menu item "Reklamationen" — one table, two lists, as with the
 * withdrawals (WithdrawalListListener).
 *
 * Both are column lists. In the menu item it is listed flat over all orders,
 * newest first, filtered by state and remedy, with the order and the
 * customer. It is closed there: a complaint is always recorded under its
 * order.
 */
class ComplaintListListener
{
    public const MODULE = 'consho_complaint';

    /** @var array<int, array<string, mixed>> order id => number and name, for this request */
    private array $orders = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly MoneyFormatter $money,
    ) {
    }

    // A hook, not `config.onload`: DC_Table reads `ptable` before it runs the onload callbacks.
    #[AsHook('loadDataContainer')]
    public function flatInModule(string $table): void
    {
        if ('tl_consho_complaint' !== $table || self::MODULE !== $this->requests->getCurrentRequest()?->query->getString('do')) {
            return;
        }

        $dca = &$GLOBALS['TL_DCA']['tl_consho_complaint'];

        // Over all orders: no parent to show, and none to create a complaint under.
        unset($dca['config']['ptable']);
        $dca['config']['closed'] = true;

        $dca['list']['sorting'] = [
            'mode' => 1,
            'fields' => ['receivedAt DESC', 'id DESC'],
            'panelLayout' => 'filter;search,limit',
            'defaultSearchField' => 'number',
        ];

        // `customer` and `lines` are virtual — see the DCA.
        $dca['list']['label'] = [
            'fields' => ['state', 'number', 'pid', 'customer', 'receivedAt', 'remedy', 'lines', 'resolvedAt'],
            'showColumns' => true,
        ];

        $dca['fields']['state']['filter'] = true;
        $dca['fields']['remedy']['filter'] = true;
    }

    /**
     * One cell per field in `list.label.fields` — both lists are column lists,
     * the menu item with the order and the customer, the one under the order
     * with what the complaint is about.
     *
     * ⚠️ An ARRAY — with showColumns Contao throws a string away.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_complaint', target: 'list.label.label')]
    public function label(array $row): array
    {
        $cells = [];

        foreach ($GLOBALS['TL_DCA']['tl_consho_complaint']['list']['label']['fields'] ?? [] as $field) {
            $cells[] = $this->cell((string) $field, $row);
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function cell(string $field, array $row): string
    {
        $remedy = (string) ($row['remedy'] ?? '');
        $resolved = (int) ($row['resolvedAt'] ?? 0);

        return match ($field) {
            'state' => $this->state($row),
            'number' => StringUtil::specialchars((string) ($row['number'] ?? '')),
            'pid' => StringUtil::specialchars((string) ($this->order($row)['orderNumber'] ?? '')),
            'customer' => StringUtil::specialchars(trim(($this->order($row)['firstname'] ?? '').' '.($this->order($row)['lastname'] ?? ''))),
            'receivedAt' => StringUtil::specialchars(BackendDate::format((int) ($row['receivedAt'] ?? 0))),
            // Not StringUtil::substr(): it strips what looks like a tag from the note.
            'note' => StringUtil::specialchars(mb_strimwidth((string) ($row['note'] ?? ''), 0, 60, '…')),
            'remedy' => $this->remedy($remedy, (string) ($row['state'] ?? '')),
            'lines' => $this->lines((int) ($row['id'] ?? 0)),
            'resolvedAt' => $resolved > 0 ? StringUtil::specialchars(BackendDate::format($resolved)) : '',
            default => StringUtil::specialchars((string) ($row[$field] ?? '')),
        };
    }

    /**
     * A rejection empties the remedy; the column says "Ablehnung" instead of
     * staying blank like an undecided complaint.
     */
    private function remedy(string $remedy, string $state): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_complaint'] ?? [];

        if ('rejected' === $state) {
            return StringUtil::specialchars((string) ($lang['remedyRejected'] ?? $state));
        }

        return '' === $remedy ? '' : StringUtil::specialchars((string) ($lang['remedies'][$remedy] ?? $remedy));
    }

    /**
     * The cracked glass of the "Reklamationen" button, coloured by state,
     * and the state in words: red while it waits for the operator, orange
     * while it is being handled, green when resolved, grey when rejected.
     *
     * @param array<string, mixed> $row
     */
    private function state(array $row): string
    {
        $state = (string) ($row['state'] ?? 'open');
        $colour = match ($state) {
            'inProgress' => StatusColour::Progress,
            'resolved' => StatusColour::Done,
            'rejected' => StatusColour::Open,
            default => StatusColour::Attention,
        };
        $text = StringUtil::specialchars((string) ($GLOBALS['TL_LANG']['tl_consho_complaint']['states'][$state] ?? $state));

        // The menu item shows the icon alone, the state in its title ("Icon reicht").
        if (self::MODULE === $this->requests->getCurrentRequest()?->query->getString('do')) {
            return \sprintf('<span class="consho-signal consho-signal--complaint consho-signal--%s" title="%s"></span>', $colour->value, $text);
        }

        return \sprintf('<span class="consho-signal consho-signal--complaint consho-signal--%s"></span>%s', $colour->value, $text);
    }

    /**
     * "2 × Kräuteressig, 1 × Kräutersalz" — what the complaint concerns.
     */
    private function lines(int $complaint): string
    {
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $lines = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT c.quantity, i.productName FROM tl_consho_complaint_item c
              LEFT JOIN tl_consho_order_item i ON i.id = c.orderItem
              WHERE c.pid = ?
              ORDER BY c.id',
            [$complaint],
        ) as $line) {
            $lines[] = \sprintf('%s × %s', $this->money->quantity((string) $line['quantity'], $language), StringUtil::specialchars((string) $line['productName']));
        }

        return implode(', ', $lines);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function order(array $row): array
    {
        $id = (int) ($row['pid'] ?? 0);

        return $this->orders[$id] ??= $this->connection->fetchAssociative('SELECT orderNumber, firstname, lastname FROM tl_consho_order WHERE id = ?', [$id]) ?: [];
    }
}

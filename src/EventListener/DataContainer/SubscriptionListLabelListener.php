<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;

/**
 * The cells of the subscription list.
 *
 *  - "Status" in front: an eye, green while the subscription is active, grey
 *    in every other state. The title says the state in words — colour alone
 *    is unreadable for anyone red-green blind, as in the withdrawal list.
 *  - The dates as days, without the time: the list is for finding a
 *    subscription, the details show the minute.
 *  - "Pause bis" (Abo pausieren, P111) only for a pause that runs or is
 *    announced. A pause that is over stays in the history — in the list it
 *    read like one still running ("Pause bis 03.10.2026" after it had ended).
 */
class SubscriptionListLabelListener
{
    private const DATES = ['startedAt', 'periodUntil', 'nextBillingAt', 'endsAt'];

    /**
     * @param \Closure(): int|null $clock for the tests
     */
    public function __construct(private readonly \Closure|null $clock = null)
    {
    }

    /**
     * @param array<string, mixed>   $row
     * @param array<int, mixed>|null $args the columns, formatted by Contao
     *
     * @return array<int, mixed>|string
     */
    #[AsCallback(table: 'tl_consho_subscription', target: 'list.label.label')]
    public function __invoke(array $row, string $label, DataContainer $dc, array|null $args = null): array|string
    {
        if (null === $args) {
            return $label;
        }

        $fields = $GLOBALS['TL_DCA']['tl_consho_subscription']['list']['label']['fields'] ?? [];
        $now = null !== $this->clock ? ($this->clock)() : time();

        foreach ($fields as $column => $field) {
            $args[$column] = match (true) {
                'status' === $field => $this->status((string) ($row['state'] ?? '')),
                \in_array($field, self::DATES, true) => $this->day($row[$field] ?? null),
                'pauseUntil' === $field => (int) ($row['pauseUntil'] ?? 0) > $now ? $this->day($row['pauseUntil']) : '-',
                default => $args[$column] ?? '',
            };
        }

        return $args;
    }

    private function status(string $state): string
    {
        $title = (string) ($GLOBALS['TL_LANG']['tl_consho_subscription']['states'][$state] ?? $state);

        return \sprintf(
            '<span class="consho-signals"><span class="consho-signal consho-signal--visible consho-signal--%s" title="%s"></span></span>',
            'active' === $state ? 'done' : 'open',
            StringUtil::specialchars($title),
        );
    }

    private function day(mixed $timestamp): string
    {
        // On one line: Contao's list breaks anywhere, "01.10.2|026" in a narrow window.
        return (int) $timestamp > 0 ? '<span class="consho-nowrap">'.StringUtil::specialchars(BackendDate::format((int) $timestamp)).'</span>' : '-';
    }
}

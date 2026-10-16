<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * The column "Pause bis" in the list of subscriptions (Abo pausieren, P111):
 * only a pause that runs or is announced. A pause that is over stays in
 * "Pausen" — in the list it read like one still running (acceptance on c5,
 * 2026-10-03: "Pause bis 03.10.2026" after it had ended).
 */
class SubscriptionPauseColumnListener
{
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
        $column = array_search('pauseUntil', $fields, true);
        $now = null !== $this->clock ? ($this->clock)() : time();

        if (false !== $column && (int) ($row['pauseUntil'] ?? 0) <= $now) {
            $args[$column] = '-';
        }

        return $args;
    }
}

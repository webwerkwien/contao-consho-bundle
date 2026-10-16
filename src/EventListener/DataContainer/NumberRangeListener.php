<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeChange;

/**
 * Refuses a counter that would hand out a number already issued (CW-5, I4);
 * the rule and why it is not "may only rise" are in NumberRangeChange.
 *
 * onbeforesubmit rather than a save callback on `current`: lowering the
 * counter is fine together with a new prefix, so the check needs both. Since
 * contao-ai v1.5.0 the record writer runs it too — the commands meet the same
 * rule as the mask (H5).
 */
final class NumberRangeListener
{
    private const RELEVANT = ['current', 'prefix', 'type'];

    public function __construct(private readonly NumberRangeChange $change)
    {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_number_range', target: 'config.onbeforesubmit')]
    public function refuseIssuedNumber(array $values, DataContainer $dc): array
    {
        if ([] === array_intersect(self::RELEVANT, array_keys($values))) {
            return $values;
        }

        $range = [...$dc->getCurrentRecord() ?? [], ...$values];
        $highest = $this->change->collision((int) ($range['pid'] ?? 0), (string) ($range['type'] ?? ''), (string) ($range['prefix'] ?? ''), (int) ($range['current'] ?? 0));

        if (null !== $highest) {
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['issued'] ?? 'Number %s has already been issued.', $highest));
        }

        $prefix = (string) ($range['prefix'] ?? '');
        $shared = $this->change->sharedPrefix((int) ($range['pid'] ?? 0), (int) ($range['id'] ?? $dc->id), (string) ($range['type'] ?? ''), $prefix);

        if (null !== $shared) {
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['shared'] ?? 'The prefix "%s" is already used by the number range for %s.', $prefix, $GLOBALS['TL_LANG']['tl_consho_number_range']['types'][$shared] ?? $shared));
        }

        return $values;
    }
}

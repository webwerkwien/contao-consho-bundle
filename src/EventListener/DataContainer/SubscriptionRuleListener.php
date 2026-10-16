<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRuleValidator;

/**
 * Refuses a subscription rule that says what it must not (Abo-1, P3–P5).
 *
 * onbeforesubmit rather than a save callback per field: the checks compare
 * fields — the interval with the term, the notice with the renewal — and a
 * save callback sees only its own new value next to the old ones. Here the
 * submitted values lie over the stored record, which is the rule as it would
 * be saved. An exception is shown as an error and nothing is written
 * (DC_Table::submit()).
 */
final class SubscriptionRuleListener
{
    public function __construct(private readonly SubscriptionRuleValidator $validator = new SubscriptionRuleValidator())
    {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_subscription_rule', target: 'config.onbeforesubmit')]
    public function refuseInvalid(array $values, DataContainer $dc): array
    {
        $errors = $this->validator->errors([...$dc->getCurrentRecord() ?? [], ...$values]);

        if ([] !== $errors) {
            $messages = array_map(
                static fn (string $key): string => (string) ($GLOBALS['TL_LANG']['tl_consho_subscription_rule']['errors'][$key] ?? $key),
                array_values($errors),
            );

            throw new \RuntimeException(implode(' ', $messages));
        }

        return $values;
    }
}

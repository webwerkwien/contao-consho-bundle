<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * "Details" of a subscription (Abo-3). The view showed quantity and net
 * as "1.0000" — a raw decimal(10,4), which MySQL returns with its trailing
 * zeros. The quantity as a person writes it, the
 * net as an amount in the subscription's currency.
 *
 * Contao keys the rows by "Label <small>field</small>", so the field is found
 * by that suffix: the label is translated, the field name is not.
 *
 * The mandate (Abo-4b, P98) only as "vorhanden" or "keines": it is what a
 * provider charges the customer with, and nobody in the back end needs to
 * read it.
 */
class SubscriptionShowListener
{
    public function __construct(private readonly MoneyFormatter $money = new MoneyFormatter())
    {
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $data
     * @param array<string, mixed>                           $record
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    #[AsCallback(table: 'tl_consho_subscription', target: 'config.onshow')]
    public function __invoke(array $data, array $record): array
    {
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');

        foreach ($data['tl_consho_subscription'][0] ?? [] as $label => $value) {
            if (str_ends_with($label, '<small>quantity</small>')) {
                $data['tl_consho_subscription'][0][$label] = $this->money->quantity((string) ($record['quantity'] ?? '0'), $language);
            } elseif (str_ends_with($label, '<small>unitNet</small>')) {
                $data['tl_consho_subscription'][0][$label] = $this->money->format((string) ($record['unitNet'] ?? '0'), (string) ($record['currency'] ?? ''), $language);
            }
        }

        // The field itself is doNotShow — the value never reaches the view.
        $lang = $GLOBALS['TL_LANG']['tl_consho_subscription'] ?? [];
        $key = '' === (string) ($record['mandate'] ?? '') ? 'mandateAbsent' : 'mandatePresent';
        $data['tl_consho_subscription'][0][($lang['mandate'][0] ?? 'Mandat').' <small>mandate</small>'] = (string) ($lang[$key] ?? $key);

        return $data;
    }
}

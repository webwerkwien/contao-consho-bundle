<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartRuleCurrency;

/**
 * A cart rule that states an amount — a fixed discount or a minimum order
 * value — needs its currency (review H, L5). The rule is CartRuleCurrency's,
 * which a command asks with the record (Connect Stufe 2, CW-4b); this
 * listener feeds it the posted form.
 *
 * Without one the rule applies nowhere: CartDiscounts compares the rule's
 * currency with the basket's, and '' matches none. The operator saw no error,
 * the customer read "not for this purchase". Refused at the field instead.
 *
 * Read from the posted form, not the record: `amount` stands before
 * `currency` in the palette and `minimumOrder` after it, so the stored record
 * may still hold the old values of either. A form that did not post the
 * fields (edit multiple names them per record) is left alone — better no
 * check than a refusal of a field nobody filled in.
 *
 * Nor on an auto-submit (second review H, L-A): switching `effect` to
 * "amount" submits the form before the operator could type a currency.
 * Contao lets empty mandatory fields through then (DataContainer, #4077),
 * but not an exception from a save callback — the message would come before
 * the field was ever offered.
 */
class CartRuleCurrencyListener
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    #[AsCallback(table: 'tl_consho_cart_rule', target: 'fields.currency.save')]
    public function save(mixed $value, DataContainer $dc): string
    {
        $currency = strtoupper(trim((string) $value));
        $post = $this->requests->getCurrentRequest()?->request;

        if ('' !== $currency || null === $post || 'auto' === $post->get('SUBMIT_TYPE')) {
            return $currency;
        }

        if (CartRuleCurrency::isMissing($currency, (string) $post->get('effect'), $post->get('minimumOrder'))) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_consho_cart_rule']['currencyMissing'] ?? 'A fixed amount or a minimum order value needs a currency.');
        }

        return $currency;
    }
}

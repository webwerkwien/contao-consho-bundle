<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Webwerkwien\ContaoConshoBundle\Catalog\EeaStates;

/**
 * Whom a shop sells to (tl_consho_shop.customerType), frozen into every order.
 *
 * `business` is the operator's statement that the shop sells to businesses
 * only. Consumer law then does not apply — the Austrian FAGG covers contracts
 * "zwischen Unternehmern und Verbrauchern" (§ 1 Abs. 1), the German
 * § 312 BGB likewise — so the shop has no withdrawal period and owes no
 * harmonised guarantee notice. Consho does not decide whether a buyer is a
 * business; it warns where the shop's setup lets anyone else buy.
 *
 * Anything unknown reads as `consumer`: the side with more duties is the safe
 * one to fall back to.
 */
enum CustomerType: string
{
    case Consumer = 'consumer';
    case Business = 'business';

    public static function of(mixed $value): self
    {
        return self::tryFrom(\is_scalar($value) ? (string) $value : '') ?? self::Consumer;
    }

    /**
     * Whether a shop with these delivery countries (serialised or a list)
     * delivers into the EU or the EEA. An empty list means "no restriction"
     * in the checkout, so it counts as delivering there.
     */
    public static function deliversIntoEuOrEea(mixed $countries): bool
    {
        $list = \is_array($countries) ? $countries : StringUtil::deserialize($countries, true);
        $codes = array_values(array_filter(array_map(static fn (mixed $code): string => \is_scalar($code) ? (string) $code : '', $list)));

        return [] === $codes || [] !== array_filter($codes, EeaStates::inEuOrEea(...));
    }
}

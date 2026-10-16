<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shop;

use Contao\StringUtil;
use Symfony\Component\Intl\Currencies;

/**
 * What a stored `tl_consho_shop.currencies` blob actually contains (C-1c).
 *
 * One parser for both readers, and that is the whole point: ShopCurrencies
 * drops what it cannot use, ShopCurrenciesListener refuses it, but they have to
 * disagree about NOTHING except the reaction. Let the two normalisations drift
 * apart and the service silently discards a value the mask had just accepted —
 * leaving a shop with no currency at all, which is precisely the state the
 * mandatory field exists to prevent.
 *
 * Checked against Symfony's ISO 4217 list, the same one the back end field
 * offers. That also settles the unreadable blob: StringUtil::deserialize()
 * hands a truncated or never-serialised value back as a one-element array
 * holding the raw text, which would otherwise travel on as if it were a
 * currency.
 */
final class CurrencySelection
{
    /**
     * @param list<string> $valid   sorted, unique, upper case
     * @param list<string> $invalid in the order met, upper case
     */
    private function __construct(
        public readonly array $valid,
        public readonly array $invalid,
    ) {
    }

    public static function fromBlob(mixed $blob): self
    {
        $valid = [];
        $invalid = [];

        foreach (array_filter(StringUtil::deserialize($blob, true), 'is_string') as $code) {
            $code = strtoupper(trim($code));

            if ('' === $code) {
                continue;
            }

            if (Currencies::exists($code)) {
                $valid[$code] = true;
            } else {
                $invalid[$code] = true;
            }
        }

        $valid = array_keys($valid);
        sort($valid);

        return new self($valid, array_keys($invalid));
    }
}

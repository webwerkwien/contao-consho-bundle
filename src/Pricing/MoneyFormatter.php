<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\CoreBundle\Util\LocaleUtil;
use Symfony\Component\Intl\Currencies;

/**
 * Turns a decimal amount into what a buyer reads.
 *
 * Rounding happens here with BigDecimal, half up, to the currency's own number
 * of decimals. Only the already rounded value becomes a float, and only because
 * NumberFormatter takes nothing else. Left to itself, ICU rounds half to even:
 * 12.605 would show as 12.60, a cent less than the shop charges.
 *
 * This is the one place where an amount becomes a float.
 */
final class MoneyFormatter
{
    /**
     * @throws \Brick\Math\Exception\NumberFormatException when the amount is not a number
     */
    public function format(string $amount, string $currency, string $language): string
    {
        $rounded = BigDecimal::of($amount)->toScale(Currencies::getFractionDigits($currency), RoundingMode::HalfUp);

        $formatter = new \NumberFormatter($this->locale($language), \NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency($rounded->toFloat(), $currency);
    }

    /**
     * A quantity for a base price or a scale threshold: "1", "100", "2,5".
     *
     * Shown as stored, without trailing zeros and without rounding — a quantity
     * is not money, and NumberFormatter would otherwise cut after three decimals.
     *
     * @throws \Brick\Math\Exception\NumberFormatException when the quantity is not a number
     */
    public function quantity(string $amount, string $language): string
    {
        $stripped = BigDecimal::of($amount)->strippedOfTrailingZeros();

        $formatter = new \NumberFormatter($this->locale($language), \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 0);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $stripped->getScale());

        return (string) $formatter->format($stripped->toFloat());
    }

    /**
     * Page languages may be language tags ("de-AT"); ICU wants a locale ID.
     */
    private function locale(string $language): string
    {
        return LocaleUtil::formatAsLocale('' !== $language ? $language : 'en');
    }
}

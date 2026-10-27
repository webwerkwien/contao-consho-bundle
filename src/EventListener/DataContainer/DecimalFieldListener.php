<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\DataContainer;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decimal fields in Consho's masks, shown the way the back end's language
 * writes numbers: "1" rather than the column's "1.0000", "2,5" in German,
 * amounts with two decimals at least — "25,00", and "12,3456" where a net
 * really has four. Some fifty fields showed the column's
 * form before, the price mask's minimum quantity among them.
 *
 * Every text field over a decimal column of a tl_consho_ table gets a
 * load_callback, unless it brings its own (durabilityYears) or is alwaysSave.
 * Amounts are marked with eval `conshoMoney`. Saving needs nothing: the
 * fields carry rgxp `digit`, and Contao turns a single decimal comma into a
 * dot before any save_callback (Widget::validator()).
 *
 * The one thing that does need handling: the mask now shows "2,5" where
 * "2.5000" is stored, and DC_Table compares the submitted "2.5" with the
 * loaded "2,5" — a save without a change would write the field, bump
 * tstamp, add a version and run every onbeforesubmit as if the quantity had
 * changed. So an onbeforesubmit, run first, drops such a field when it
 * equals the stored value, and with nothing else left the whole save.
 * Only for the mask's own form: contao-ai and Connect run onbeforesubmit as
 * well, with values that never came from a display, and an empty set of
 * values would be an UPDATE without columns there.
 *
 * Priority -32: after DataContainerCallbackListener (-16), so the fields'
 * own #[AsCallback] load callbacks are already in the DCA.
 */
#[AsHook('loadDataContainer', priority: -32)]
final class DecimalFieldListener
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function __invoke(string $table): void
    {
        if (!str_starts_with($table, 'tl_consho_') || !\is_array($GLOBALS['TL_DCA'][$table]['fields'] ?? null)) {
            return;
        }

        $formatted = [];

        foreach ($GLOBALS['TL_DCA'][$table]['fields'] as $name => $field) {
            if (!self::isShownDecimal($field)) {
                continue;
            }

            $money = (bool) ($field['eval']['conshoMoney'] ?? false);
            $formatted[] = $name;

            $GLOBALS['TL_DCA'][$table]['fields'][$name]['load_callback'] = [
                static fn (mixed $value): mixed => self::display($value, $money, self::separator()),
            ];
        }

        if ([] === $formatted) {
            return;
        }

        $requests = $this->requests;

        $GLOBALS['TL_DCA'][$table]['config']['onbeforesubmit_callback'] ??= [];
        array_unshift(
            $GLOBALS['TL_DCA'][$table]['config']['onbeforesubmit_callback'],
            static fn (array $values, DataContainer $dc): array => self::withoutUnchanged($values, $dc, $table, $formatted, $requests),
        );
    }

    /**
     * A stored decimal as the mask shows it. Anything that is not a plain
     * decimal (NULL, '', a value already shown) comes back as it is.
     */
    public static function display(mixed $value, bool $money, string $separator): mixed
    {
        if (!\is_string($value) || 1 !== preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

        $number = BigDecimal::of($value)->strippedOfTrailingZeros();

        if ($money && $number->getScale() < 2) {
            $number = $number->toScale(2);
        }

        return str_replace('.', $separator, (string) $number);
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function isShownDecimal(array $field): bool
    {
        return 'text' === ($field['inputType'] ?? null)
            && 'decimal' === ($field['sql']['type'] ?? null)
            && empty($field['load_callback'])
            && !($field['eval']['alwaysSave'] ?? false);
    }

    private static function separator(): string
    {
        return (string) ($GLOBALS['TL_LANG']['MSC']['decimalSeparator'] ?? '.');
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $fields
     *
     * @return array<string, mixed>
     */
    private static function withoutUnchanged(array $values, DataContainer $dc, string $table, array $fields, RequestStack $requests): array
    {
        if ($table !== $requests->getCurrentRequest()?->request->get('FORM_SUBMIT')) {
            return $values;
        }

        $record = $dc->getCurrentRecord() ?? [];

        foreach ($fields as $name) {
            if (\array_key_exists($name, $values) && self::same($values[$name], $record[$name] ?? null)) {
                unset($values[$name]);
            }
        }

        // tstamp and ptable are DC_Table's own, set because something changed.
        return [] === array_diff(array_keys($values), ['tstamp', 'ptable']) ? [] : $values;
    }

    private static function same(mixed $submitted, mixed $stored): bool
    {
        if (!is_numeric($submitted) || !is_numeric($stored)) {
            return false;
        }

        try {
            return BigDecimal::of((string) $submitted)->isEqualTo((string) $stored);
        } catch (MathException) {
            return false;
        }
    }
}

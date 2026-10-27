<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * The coupled mask of shipping amounts (spec 5.1, 5.3): the same chain as
 * product prices, with the method's reference tax class (as decided for prices).
 *
 * One saved amount per method and currency. Not a UNIQUE index: a row the mask
 * creates on "new" already carries the preset currency, and a second "new"
 * before saving would fail on the index with an SQL error.
 */
class ShippingPriceInputListener extends CoupledPriceInputListener
{
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        parent::onLoad($dc);
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'fields.currency.options')]
    public function currencyOptions(): array
    {
        return parent::currencyOptions();
    }

    #[AsCallback(table: 'tl_consho_shipping_price', target: 'fields.currency.save')]
    public function saveCurrency(mixed $value, DataContainer|null $dc = null): string
    {
        return parent::saveCurrency($value, $dc);
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'fields.inputCountry.options')]
    public function countryOptions(DataContainer|null $dc = null): array
    {
        return parent::countryOptions($dc);
    }

    #[AsCallback(table: 'tl_consho_shipping_price', target: 'fields.amount.load')]
    public function loadAmount(mixed $value, DataContainer $dc): string
    {
        return parent::loadAmount($value, $dc);
    }

    #[AsCallback(table: 'tl_consho_shipping_price', target: 'fields.inputMode.load')]
    public function loadInputMode(mixed $value): string
    {
        return parent::loadInputMode($value);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'config.onbeforesubmit')]
    public function onBeforeSubmit(array $values, DataContainer $dc): array
    {
        return parent::onBeforeSubmit($values, $dc);
    }

    #[AsCallback(table: 'tl_consho_shipping_price', target: 'config.oncopy')]
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        parent::onCopy($insertId, $dc);
    }

    protected function table(): string
    {
        return 'tl_consho_shipping_price';
    }

    protected function taxClassOf(array $record): int
    {
        return (int) $this->connection->fetchOne('SELECT taxClass FROM tl_consho_shipping_method WHERE id = ?', [(int) ($record['pid'] ?? 0)]);
    }

    protected function taxClassMissingKey(): string
    {
        return 'shippingPriceTaxClassMissing';
    }

    protected function checkBeforeConversion(array $values, array $record): void
    {
        $currency = (string) self::valueOf($values, $record, 'currency');
        $maxWeight = self::bracketOf(self::valueOf($values, $record, 'maxWeight'));

        if ($this->takenCurrency((int) ($record['pid'] ?? 0), $currency, $maxWeight, (int) ($record['id'] ?? 0))) {
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['shippingPriceDuplicate'], $currency));
        }
    }

    /**
     * A saved copy in a currency that already has a saved amount becomes a
     * draft (tstamp 0): it only counts once saved, and saving runs the
     * duplicate check above.
     *
     * Needed because copyAll calls DC_Table::copy(true), which stamps the copy
     * with time(), and copyChildren() does the same — neither runs
     * onbeforesubmit, so checkBeforeConversion() never sees these rows.
     */
    protected function afterCopy(int $insertId): void
    {
        $copy = $this->connection->fetchAssociative('SELECT id, pid, tstamp, currency, maxWeight FROM tl_consho_shipping_price WHERE id = ?', [$insertId]);

        if (false === $copy || (int) $copy['tstamp'] < 1) {
            return;
        }

        if ($this->takenCurrency((int) $copy['pid'], (string) $copy['currency'], self::bracketOf($copy['maxWeight'] ?? null), $insertId)) {
            $this->connection->update('tl_consho_shipping_price', ['tstamp' => 0], ['id' => $insertId]);
        }
    }

    /**
     * Whether another saved amount already uses this currency — and this upper
     * weight — for this method.
     *
     * The weight is part of the key since E-4: a weight rate has one row per
     * bracket and currency, and two rows "bis 5 kg" in euro would make the
     * price a matter of chance, exactly what the rule prevents for the flat
     * rate, whose rows all carry 0. Before, the second bracket
     * could not be saved.
     *
     * tstamp > 0: a row opened and never saved is no amount (C-1b;
     * Block C, section 5.3).
     */
    private function takenCurrency(int $pid, string $currency, string $maxWeight, int $excludeId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_consho_shipping_price WHERE pid = ? AND currency = ? AND maxWeight = ? AND id != ? AND tstamp > 0',
            [$pid, $currency, $maxWeight, $excludeId],
        );
    }

    /**
     * The upper weight as the column stores it, '0.0000' for none.
     */
    private static function bracketOf(mixed $value): string
    {
        $value = \is_string($value) ? str_replace(',', '.', trim($value)) : $value;

        return is_numeric($value) ? (string) BigDecimal::of((string) $value)->toScale(4, RoundingMode::HalfUp) : '0.0000';
    }
}

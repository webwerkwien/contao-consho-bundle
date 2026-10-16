<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;

/**
 * The coupled mask of product prices (C22, spec 5.3). The logic is in
 * CoupledPriceInputListener; this class names the table and attaches the
 * callbacks — each override exists only to carry its attribute.
 *
 * The tax class is the one of the product the row hangs off
 * (tl_consho_price.pid → tl_consho_product.taxClass). A variant with another
 * class that inherits these prices shows another gross — intended since C-1a.
 */
class PriceInputListener extends CoupledPriceInputListener
{
    #[AsCallback(table: 'tl_consho_price', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        parent::onLoad($dc);
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_price', target: 'fields.currency.options')]
    public function currencyOptions(): array
    {
        return parent::currencyOptions();
    }

    #[AsCallback(table: 'tl_consho_price', target: 'fields.currency.save')]
    public function saveCurrency(mixed $value, DataContainer|null $dc = null): string
    {
        return parent::saveCurrency($value, $dc);
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_price', target: 'fields.inputCountry.options')]
    public function countryOptions(DataContainer|null $dc = null): array
    {
        return parent::countryOptions($dc);
    }

    #[AsCallback(table: 'tl_consho_price', target: 'fields.amount.load')]
    public function loadAmount(mixed $value, DataContainer $dc): string
    {
        return parent::loadAmount($value, $dc);
    }

    #[AsCallback(table: 'tl_consho_price', target: 'fields.inputMode.load')]
    public function loadInputMode(mixed $value): string
    {
        return parent::loadInputMode($value);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_price', target: 'config.onbeforesubmit')]
    public function onBeforeSubmit(array $values, DataContainer $dc): array
    {
        return parent::onBeforeSubmit($values, $dc);
    }

    #[AsCallback(table: 'tl_consho_price', target: 'config.oncopy')]
    public function onCopy(int|string $insertId, DataContainer $dc): void
    {
        parent::onCopy($insertId, $dc);
    }

    protected function table(): string
    {
        return 'tl_consho_price';
    }

    protected function taxClassOf(array $record): int
    {
        return (int) $this->connection->fetchOne('SELECT taxClass FROM tl_consho_product WHERE id = ?', [(int) ($record['pid'] ?? 0)]);
    }

    protected function taxClassMissingKey(): string
    {
        return 'priceTaxClassMissing';
    }
}

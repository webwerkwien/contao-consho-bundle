<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\DeliveryCountryProvider;

/**
 * Says which delivery countries cannot be priced, right after saving the shop.
 *
 * The missing half of section 7: the whitelist is the truth, so nothing removes
 * an under-configured country any more — which only works if something says so.
 * The moment of the country choice is where the operator can act, so that is
 * where the message belongs, rather than in a report nobody opens.
 *
 * Deliberately an info message and not an error: the configuration was saved,
 * and it is legitimate to tick a country now and enter its rates next. The
 * consequence is spelled out in the text instead, because that is what makes
 * someone act.
 */
class ShopTaxCompletenessListener
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly DeliveryCountryProvider $deliveryCountries,
        private readonly CountryProvider $countries,
        private readonly Connection $connection,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'config.onsubmit')]
    public function __invoke(DataContainer $dc): void
    {
        $shop = $this->framework->getAdapter(ShopModel::class)->findByPk((int) $dc->id);

        if (!$shop instanceof ShopModel) {
            return;
        }

        // The row was written moments ago, but the model registry may still
        // hold the state from before this request. Ask the database again.
        $shop->refresh();

        $incomplete = $this->deliveryCountries->incompleteFor($shop, time());

        if ([] === $incomplete) {
            return;
        }

        $classTitles = $this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_tax_class');
        $countryLabels = $this->countries->all($GLOBALS['TL_LANGUAGE'] ?? 'en');

        foreach ($incomplete as $country => $taxClassIds) {
            $classes = array_map(
                static fn (int $id): string => $classTitles[$id] ?? (string) $id,
                $taxClassIds,
            );

            Message::addInfo(sprintf(
                $GLOBALS['TL_LANG']['tl_consho_shop']['taxRateMissing'],
                $countryLabels[$country] ?? $country,
                implode(', ', $classes),
            ));
        }
    }
}

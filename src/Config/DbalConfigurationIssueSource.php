<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\DeliveryCountryProvider;

/**
 * Gathers the findings the back end start page shows.
 *
 * ⚠️ **Called on every single call of the start page.** Everything here is
 * either a plain read of the shop rows or a lookup on an indexed column. The
 * full tax diagnosis over all shops belongs in `consho:tax:diagnose`, not here
 * — a back end that an extension makes sluggish gets switched off, not fixed.
 */
final class DbalConfigurationIssueSource implements ConfigurationIssueSource
{
    /**
     * 🔴 **`$framework` steht hier, damit die Klasse prüfbar wird.**
     *
     * Sie war die letzte Stelle in `src/`, die ein Modell statisch holte
     * (`ShopModel::findByPk()`), und — kein Zufall — die einzige Befundquelle
     * ohne Test: Ein statischer Aufruf lässt sich nicht ersetzen, also hätte
     * ein Test Datenbank **und** initialisiertes Framework gebraucht. Über den
     * Adapter ist er ein Mock. Gleiche Ursache wie bei `DiagnoseTaxCommand`,
     * gefunden beim Nachziehen von dessen Test am 2026-09-23.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigurationCheck $check,
        private readonly DeliveryCountryProvider $countries,
        private readonly CountryProvider $countryNames,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function issues(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            'SELECT id, title, vatId, mailTransport, deliveryCountries, country FROM tl_consho_shop',
        );

        if ([] === $shops) {
            return [];
        }

        $issues = $this->check->duplicateVatIds(array_map(
            static fn (array $row): array => [
                'title' => (string) $row['title'],
                'vatId' => (string) $row['vatId'],
            ],
            $shops,
        ));

        $taxClassTitles = $this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_tax_class');

        /*
         * Country NAMES, not ISO codes. The message on saving the shop
         * configuration has said "Dänemark" since A-3; this one said "DK" for
         * the same finding. Two wordings for one fact make an operator wonder
         * whether they are two facts.
         */
        $countryNames = $this->countryNames->all($GLOBALS['TL_LANGUAGE'] ?? 'en');
        $now = time();

        foreach ($shops as $row) {
            $issues = [
                ...$issues,
                /*
                 * ⚠️ Mail-Transport und Heimatland liegen NICHT hier, sondern
                 * in DbalCheckoutReadinessIssueSource. Beide sperren den
                 * Checkout (11.2), und der fragt bei jedem Seitenaufruf im
                 * Frontend nach — diese Quelle bringt daneben die ganze
                 * Steuerdiagnose mit, eine Abfrage je Steuerklasse je Shop.
                 * Im zweiten Review vom 2026-09-22 gemessen.
                 */
            ];

            /*
             * Skipped when the shop has neither delivery countries nor a home
             * country yet: a fresh configuration would otherwise report every
             * tax class as missing and bury the findings that matter under a
             * wall of noise. A home country alone is enough to check — its
             * rates make the gross of every price shown (C25).
             */
            if ([] === array_filter(StringUtil::deserialize($row['deliveryCountries'], true)) && '' === (string) $row['country']) {
                continue;
            }

            $shop = $this->framework->getAdapter(ShopModel::class)->findByPk((int) $row['id']);

            if (null === $shop) {
                continue;
            }

            $issues = [
                ...$issues,
                ...$this->check->fromIncompleteTaxRates(
                    $this->countries->incompleteFor($shop, $now),
                    $taxClassTitles,
                    (string) $row['title'],
                    (int) $row['id'],
                    $countryNames,
                ),
            ];
        }

        return $issues;
    }
}

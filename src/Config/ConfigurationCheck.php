<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

use Webwerkwien\ContaoConshoBundle\Tax\ShopVatId;

/**
 * Makes incomplete configuration visible instead of letting it fail silently.
 *
 * This is the middle of the three layers from the specification: the delivery
 * countries are a whitelist, so a country without a rate is offered and then
 * fails at checkout. That is loud in the right place but invisible in the back
 * end — the operator learns of it from a customer. This is where it gets said
 * beforehand.
 *
 * ⚠️ **It computes nothing that another class already computes.**
 *
 * The plan had a `missingTaxRates()` here that re-derived which country lacks
 * which rate — a rule DeliveryCountryProvider::incompleteFor() already
 * implements. Two implementations of one rule drift apart, and the one that
 * drifts is always the one nobody is looking at. So this class TRANSLATES the
 * existing answer into findings and adds only what is genuinely new.
 */
final class ConfigurationCheck
{
    /**
     * 🔴 **`$shopTitle` and `$shopId` are required, on purpose.**
     *
     * They did not exist at first: the finding named country and tax class,
     * but not which shop was meant — the only one of the three here.
     * `mailTransport()` and `homeCountry()` have always taken both. Where two
     * shops deliver into the same country and the same rate is missing there,
     * **two identical lines** stood on the start page.
     *
     * It never shows while only one shop delivers into a country — which is why
     * nobody noticed, and why the parameter has **no default**: a `= 0` would be
     * the way back.
     *
     * @param array<string, list<int>> $incomplete     ISO code => tax class ids without a rate, from
     *                                                 DeliveryCountryProvider::incompleteFor()
     * @param array<int, string>       $taxClassTitles id => title
     * @param array<string, string>    $countryNames   ISO code => translated name
     *
     * @return list<ConfigurationIssue>
     */
    public function fromIncompleteTaxRates(
        array $incomplete,
        array $taxClassTitles,
        string $shopTitle,
        int $shopId,
        array $countryNames = [],
    ): array {
        $issues = [];

        foreach ($incomplete as $country => $taxClassIds) {
            foreach ($taxClassIds as $taxClassId) {
                $issues[] = new ConfigurationIssue(
                    ConfigurationFinding::TaxRateMissing,
                    [
                        /*
                         * Raw from the database, not defused here:
                         * BackendSystemMessagesListener runs every %key% value
                         * through decodeEntities() and specialchars(). That was
                         * the C-6 correction, and it applies centrally — decoding
                         * a second time here would be a second truth.
                         */
                        'shop' => $shopTitle,
                        // The name where it is known, the code otherwise. The
                        // message on saving the shop configuration says
                        // "Dänemark"; this one must not say "DK" for the same
                        // finding.
                        'country' => $countryNames[(string) $country] ?? (string) $country,

                        /*
                         * A deleted tax class leaves an id without a title.
                         * Naming the bare id is ugly but findable; dropping the
                         * issue would hide a country that cannot be priced.
                         */
                        'taxClass' => $taxClassTitles[$taxClassId] ?? '#'.$taxClassId,
                    ],
                    $shopId,
                );
            }
        }

        return $issues;
    }

    /**
     * Two shop configurations sharing one VAT registration.
     *
     * A multilingual shop belongs on ONE configuration, because a second starts
     * its own invoice number sequence. Consho cannot forbid a second one —
     * several configurations are multi-tenancy and therefore a feature — but
     * one business has one VAT registration, so sharing it is a strong sign of
     * a setup mistake.
     *
     * Reported as a note rather than a warning: two brands of one company may
     * legitimately run separate sequences, as long as each is unbroken. The
     * finding asks; it does not accuse.
     *
     * @param list<array{title: string, vatId: string}> $shops
     *
     * @return list<ConfigurationIssue>
     */
    public function duplicateVatIds(array $shops): array
    {
        $byVatId = [];

        foreach ($shops as $shop) {
            $vatId = ShopVatId::key($shop['vatId']);

            // A shop that has not entered one yet is not a duplicate of every
            // other shop that has not either — that would fire on any fresh
            // installation with two shops.
            if ('' === $vatId) {
                continue;
            }

            $byVatId[$vatId][] = $shop['title'];
        }

        $issues = [];

        foreach ($byVatId as $vatId => $titles) {
            if (\count($titles) < 2) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::DuplicateVatId,
                ['vatId' => (string) $vatId, 'shops' => implode(', ', $titles)],
            );
        }

        return $issues;
    }

    /**
     * @return list<ConfigurationIssue>
     */
    public function mailTransport(int $mailTransportId, string $shopTitle, int $shopId = 0): array
    {
        if ($mailTransportId > 0) {
            return [];
        }

        return [new ConfigurationIssue(
            ConfigurationFinding::MailTransportMissing,
            ['shop' => $shopTitle],
            $shopId,
        )];
    }

    /**
     * A shop without a home country has no gross anywhere (C25): its listings
     * and product pages show no price, and nothing about it looks broken.
     *
     * @return list<ConfigurationIssue>
     */
    public function homeCountry(string $country, string $shopTitle, int $shopId = 0): array
    {
        if ('' !== trim($country)) {
            return [];
        }

        return [new ConfigurationIssue(
            ConfigurationFinding::HomeCountryMissing,
            ['shop' => $shopTitle],
            $shopId,
        )];
    }
}

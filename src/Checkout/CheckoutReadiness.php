<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Whether a shop may take an order at all (11.2).
 *
 * ── "Nur Warnungen sperren" ───────────────────────────────────────────────
 *
 * Notices never block, whatever they say. That is the whole rule, and it is
 * the reason the finding vocabulary carries isWarning() rather than every
 * caller deciding for itself: a shop stopped by a note about a duplicate GTIN
 * would teach its operator to ignore notes, and then the warnings go unread
 * too.
 *
 * ── Checked BEFORE the buy click, not after ───────────────────────────────
 *
 * The specification says so in one sentence, and it is the expensive half: a
 * readiness check that ran at the buy click would refuse an order after the
 * visitor typed an address, chose a method and read the legal texts. This is
 * asked when the checkout element renders.
 *
 * ── Only the findings that block a checkout ───────────────────────────────
 *
 * A warning about a projection that drifted (ListPriceDeviation) is serious
 * and has nothing to do with taking an order — `consho:price:rebuild` fixes it
 * while the shop sells on. So it is not the warning flag alone that stops the
 * checkout; it is the flag AND membership in this list, which is written out
 * rather than derived, so that adding a finding never blocks a shop by
 * accident.
 *
 * -- Its own tag, not the back end's --------------------------------------
 *
 * `consho.checkout_readiness_source`, carried by the two sources that can
 * produce a blocking finding. The back end's tag hangs on eighteen sources
 * including the whole catalogue check — and this runs on every checkout page
 * view and every POST in the FRONT end, where 11.3's "counting queries only"
 * was never the budget. A source that grows a blocking finding adds the second
 * tag; a test holds the tag and the list together.
 *
 * -- Per shop, not per installation ---------------------------------------
 *
 * 11.2 says "den Checkout des betroffenen Shops". A finding carries the shop
 * it is about (ConfigurationIssue::$shopId); 0 means it applies to all of
 * them. Without this, a second shop missing a payment method would stop the
 * first one from selling — and multiple shops are exactly what C-1c and C-3b
 * are about.
 */
final class CheckoutReadiness
{
    /**
     * Public for the setup assistant (block I), whose test holds every entry
     * to one of its steps — a new reason to block cannot slip past it.
     *
     * @var list<ConfigurationFinding>
     */
    public const BLOCKING = [
        ConfigurationFinding::CheckoutPageMissing,
        ConfigurationFinding::CheckoutElementMissing,
        ConfigurationFinding::ShippingMethodMissing,
        ConfigurationFinding::PaymentMethodMissing,
        ConfigurationFinding::HomeCountryMissing,
        ConfigurationFinding::MailTransportMissing,

        // C-6. Written out here as well, deliberately: the list is the second
        // half of the decision, and a finding that becomes a warning must not
        // start blocking checkouts without anybody saying so.
        ConfigurationFinding::CartPageMissing,
        ConfigurationFinding::CartElementMissing,
        ConfigurationFinding::OrderPageMissing,
        ConfigurationFinding::OrderElementMissing,
        ConfigurationFinding::LegalTextMissing,
        ConfigurationFinding::LegalTextUnreachable,
        ConfigurationFinding::PrivacyPageMissing,
        ConfigurationFinding::WarrantyNoticeMissing,
        ConfigurationFinding::WarrantyNoticeFileMissing,
        ConfigurationFinding::InvoiceSenderIncomplete,
        ConfigurationFinding::NumberRangeMissing,

        // D-2 (E1): only where the shop sits in the EU/EEA; elsewhere the
        // source reports WithdrawalFunctionAdvised, which is not listed here.
        ConfigurationFinding::WithdrawalFunctionMissing,
    ];

    /**
     * The tag whose sources can block. Repeated as a literal in the sources
     * themselves; a test holds them together, the way ShippingMethodTypes does.
     */
    public const TAG = 'consho.checkout_readiness_source';

    /**
     * ⚠️ Der Tag muss dastehen. `iterable` ist ein Builtin-Typ, und Autowiring
     * kann daraus nichts ableiten — der Container baut dann GAR NICHT,
     * `cache:clear` bricht ab, und die Seite läuft still mit dem alten Code
     * weiter. Dieselbe Mechanik wie der Vorfall vom 2026-09-21, eine Variante
     * tiefer; IterableArgumentsAreTaggedTest hält beide Fälle fest.
     *
     * @param iterable<ConfigurationIssueSource> $sources
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
        private readonly iterable $sources,
    ) {
    }

    /**
     * The findings blocking THIS shop right now, in the order the sources
     * report them.
     *
     * @return list<ConfigurationFinding>
     */
    public function blockers(int $shopId): array
    {
        $blockers = [];

        foreach ($this->sources as $source) {
            foreach ($source->issues() as $issue) {
                // A shop id of 0 means "no shop in particular", and that blocks
                // everyone — the safe reading for a source that does not say which.
                if (self::blocks($issue, $shopId) && !\in_array($issue->finding, $blockers, true)) {
                    $blockers[] = $issue->finding;
                }
            }
        }

        return $blockers;
    }

    /**
     * Whether this finding blocks this shop's checkout — the rule of
     * blockers(), for findings someone already holds (the dashboard's
     * "Einrichtung unvollständig", DB9).
     */
    public static function blocks(ConfigurationIssue $issue, int $shopId): bool
    {
        return $issue->finding->isWarning()
            && \in_array($issue->finding, self::BLOCKING, true)
            && (0 === $issue->shopId || $issue->shopId === $shopId);
    }

    public function isReady(int $shopId): bool
    {
        return [] === $this->blockers($shopId);
    }
}

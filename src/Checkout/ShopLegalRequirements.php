<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\EeaStates;

/**
 * Which legal texts a shop MUST have before it may sell (D-2, decision E1).
 *
 * Michael, 2026-09-27: "Sperre bei AGB ist schon falsch, man muss keine
 * haben. Sonst nur sperrend, wenn man in der EU Betreiber ist."
 *
 *  - Terms: only where the shop asks the buyer to agree to them
 *    (`termsRequired`). A tick box for nothing is the one case a missing text
 *    breaks; without it, terms are optional.
 *  - Withdrawal instruction: only where the shop is established in the EU or
 *    the EEA (home country) AND has a withdrawal period. Elsewhere it is a
 *    notice, not a block.
 *
 * One place for the answer, asked by the readiness check (may the checkout
 * open?) and by the purchase (may the order be written?). Until D-2 both
 * required both texts, each on its own — and a shop without terms could
 * neither open its checkout nor, had it got past, place an order.
 *
 * Whatever text IS configured is still shown and frozen at the purchase, as
 * before: optional means "may be missing", not "is ignored".
 */
class ShopLegalRequirements
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<LegalTextKind>
     */
    public static function of(bool $termsRequired, string $homeCountry, int $withdrawalDays): array
    {
        $required = [];

        if ($termsRequired) {
            $required[] = LegalTextKind::Terms;
        }

        if (self::withdrawalRequired($homeCountry, $withdrawalDays)) {
            $required[] = LegalTextKind::Withdrawal;
        }

        return $required;
    }

    /**
     * The withdrawal instruction and the function "Vertrag widerrufen" block
     * under the same condition (E1).
     */
    public static function withdrawalRequired(string $homeCountry, int $withdrawalDays): bool
    {
        return $withdrawalDays > 0 && EeaStates::inEuOrEea($homeCountry);
    }

    /**
     * @return list<LegalTextKind>
     */
    public function forShop(int $shopId): array
    {
        $shop = $this->connection->fetchAssociative('SELECT termsRequired, country, withdrawalDays FROM tl_consho_shop WHERE id = ?', [$shopId]);

        // An unknown shop needs everything: the safe answer where the question
        // cannot be put.
        if (false === $shop) {
            return LegalTextKind::cases();
        }

        return self::of((bool) $shop['termsRequired'], (string) $shop['country'], (int) $shop['withdrawalDays']);
    }
}

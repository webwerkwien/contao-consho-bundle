<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * The three EEA states outside the EU (D-2, spec D 6.2).
 *
 * Kept apart from EuMemberStates on purpose: that list answers EU questions —
 * VAT, reverse charge — where Iceland, Liechtenstein and Norway are third
 * countries. Consumer law is different: Directive 2011/83/EU applies in the
 * EEA through the EEA Agreement, and the withdrawal right with it. So the
 * withdrawal findings ask EU OR EEA, and nothing else should.
 */
final class EeaStates
{
    public const CODES = ['IS', 'LI', 'NO'];

    public static function inEuOrEea(string $countryCode): bool
    {
        return EuMemberStates::contains($countryCode) || \in_array(strtoupper(trim($countryCode)), self::CODES, true);
    }
}

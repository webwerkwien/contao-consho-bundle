<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * The member states of the European Union.
 *
 * Deliberately a named list with a date rather than a literal buried in a
 * condition: it changes rarely, but it changes, and when it does somebody has
 * to be able to find it.
 *
 * ⚠️ Stand 2026-09-10, 27 states. Brexit took the UK out in 2020; no accession
 * or withdrawal is pending. Check before assuming this is still current.
 *
 * Only used to decide whether a manufacturer needs a responsible person inside
 * the Union (GPSR Art. 16). That is not the same question as "where do we
 * deliver" and not the same as the EEA: Norway, Iceland and Liechtenstein are
 * EEA but not EU, and a manufacturer seated there needs one too.
 */
final class EuMemberStates
{
    /**
     * @var list<string>
     */
    public const CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK',
    ];

    public static function contains(string $countryCode): bool
    {
        return \in_array(strtoupper(trim($countryCode)), self::CODES, true);
    }
}

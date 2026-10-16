<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\EeaStates;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A withdrawal period under the statutory 14 days while the shop delivers
 * into the EU or the EEA (D-2, W4).
 *
 * Michael, 2026-09-27: "der betreiber soll einstellen, bis wann ein widerruf
 * möglich ist, wobei der gesetzlich Wert der default ist … für einen shop in
 * der eu unter dem default eine warnung zeigen".
 *
 * The DELIVERY country decides, not the seat: a shop from outside the EU that
 * sells to consumers in it owes them their withdrawal right (Art. 6 Rome I —
 * an interpretation, not read at the source; enough for a warning, which
 * blocks nothing). An empty delivery list means "no restriction" in the
 * checkout (CheckoutCalculator), so it counts as delivering into the EU.
 *
 * 0 days is included: it switches the function off, and a shop that delivers
 * into the EU without one is the sharpest case of all.
 *
 * Back end only — not a checkout readiness source, because it never blocks.
 */
final class WithdrawalPeriodIssueSource implements ConfigurationIssueSource
{
    private const STATUTORY_DAYS = 14;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, withdrawalDays, deliveryCountries FROM tl_consho_shop ORDER BY id') as $row) {
            $days = (int) $row['withdrawalDays'];

            if ($days >= self::STATUTORY_DAYS) {
                continue;
            }

            $countries = array_values(array_filter(array_map(strval(...), StringUtil::deserialize($row['deliveryCountries'], true))));
            $affected = [] === $countries ? ['*'] : array_values(array_filter($countries, EeaStates::inEuOrEea(...)));

            if ([] === $affected) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::WithdrawalPeriodShort,
                [
                    'shop' => (string) $row['title'],
                    'days' => (string) $days,
                    // The word where no language file is loaded — tests, the console.
                    'countries' => ['*'] === $affected
                        ? (string) ($GLOBALS['TL_LANG']['CONSHO']['withdrawal']['allCountries'] ?? 'all')
                        : implode(', ', $affected),
                ],
                (int) $row['id'],
            );
        }

        return $issues;
    }
}

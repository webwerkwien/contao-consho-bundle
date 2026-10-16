<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * One saved rate per tax class, country and "valid from" (block A, open point
 * of 2026-10-11).
 *
 * Rates may overlap on purpose — the later "valid from" supersedes the earlier
 * one (ContaoTaxRateRepository::findRate()). Two rates with the SAME start
 * leave nothing to decide by: found at the CW-5 acceptance on c5, where an
 * aborted run had created two equal rates and neither the mask nor the
 * commands refused it. Two empty "valid from" are the same start.
 *
 * onbeforesubmit, so the mask and, since contao-ai v1.5.0, the commands meet
 * the rule. Every save carries tstamp, so it always runs — also when a draft
 * is saved, the moment a copy starts to count.
 *
 * Not a UNIQUE index, for the same reason as ShippingPriceInputListener: a
 * copy exists before it is saved. Undo and restoring a version bypass this
 * check; findRate() stays deterministic for that case.
 */
final class TaxRateDuplicateListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.onbeforesubmit')]
    public function refuseDuplicate(array $values, DataContainer $dc): array
    {
        $rate = [...$dc->getCurrentRecord() ?? [], ...$values];

        if ($this->taken((int) ($rate['pid'] ?? 0), (string) ($rate['country'] ?? ''), self::start($rate['validFrom'] ?? null), (int) ($rate['id'] ?? $dc->id))) {
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['tl_consho_tax_rate']['errors']['duplicate'] ?? 'This tax class already has a rate for %s with the same "valid from".', (string) ($rate['country'] ?? '')));
        }

        return $values;
    }

    /**
     * A copy that would form a pair becomes a draft (tstamp 0): it counts once
     * saved, and saving runs the check above. A single copy is a draft anyway
     * (setting it to 0 again changes nothing); "copy multiple"
     * (DC_Table::copy(true)) stamps it with time() and runs no onbeforesubmit.
     */
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'config.oncopy')]
    public function draftDuplicateCopy(int|string $insertId, DataContainer $dc): void
    {
        $copy = $this->connection->fetchAssociative('SELECT id, pid, country, validFrom FROM tl_consho_tax_rate WHERE id = ?', [(int) $insertId]);

        if (false === $copy) {
            return;
        }

        if ($this->taken((int) $copy['pid'], (string) $copy['country'], self::start($copy['validFrom']), (int) $copy['id'])) {
            $this->connection->update('tl_consho_tax_rate', ['tstamp' => 0], ['id' => (int) $copy['id']]);
        }
    }

    /**
     * tstamp > 0: a row opened and never saved is no rate.
     */
    private function taken(int $pid, string $country, int|null $validFrom, int $excludeId): bool
    {
        $start = null === $validFrom ? 'validFrom IS NULL' : 'validFrom = '.$validFrom;

        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_consho_tax_rate WHERE pid = ? AND country = ? AND '.$start.' AND id != ? AND tstamp > 0',
            [$pid, $country, $excludeId],
        );
    }

    private static function start(mixed $value): int|null
    {
        return null === $value || '' === $value ? null : (int) $value;
    }
}

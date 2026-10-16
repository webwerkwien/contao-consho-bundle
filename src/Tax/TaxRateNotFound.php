<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

/**
 * No tax rate is configured for this class, country and date.
 *
 * This is deliberately fatal. Falling back to zero would produce invoices that
 * are wrong and look right — see docs/block-a-fundament.md, section 7.
 */
final class TaxRateNotFound extends \RuntimeException
{
    public static function for(int $taxClassId, string $countryCode, int $timestamp): self
    {
        return new self(sprintf(
            'No tax rate configured for tax class %d in country %s at %s.',
            $taxClassId,
            $countryCode,
            date('Y-m-d', $timestamp),
        ));
    }
}

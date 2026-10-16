<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Address;

/**
 * Three states, not two.
 *
 * "Could not check" is not "invalid" and certainly not "valid". VIES is
 * routinely unavailable per member state, and reverse charge granted on an
 * unverified ID is a tax risk that lands on the shop owner.
 */
enum VatIdStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';

    /**
     * Only a verified valid ID grants reverse charge. On anything else tax
     * applies — the safe direction is charging, never exempting.
     */
    public function grantsReverseCharge(): bool
    {
        return self::Valid === $this;
    }
}

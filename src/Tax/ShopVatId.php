<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tax;

/**
 * A shop's own VAT ID as an identity: the business behind the shop.
 *
 * Consho has no entity above the shop, so shops that share this key belong
 * to one business — what the OSS threshold counts and the switch "Steuer im
 * Bestimmungsland" applies to (block R, O2, O3).
 */
final class ShopVatId
{
    /**
     * Case and spacing are formatting, not identity: "ATU 1234 5678" and
     * "atu12345678" are one registration.
     */
    public static function key(string $vatId): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($vatId)) ?? '');
    }
}

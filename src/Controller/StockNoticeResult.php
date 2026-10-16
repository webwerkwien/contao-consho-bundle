<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller;

/**
 * What the stock notice route answers (Block G, G12, G15), in the address of
 * the product page it sends the visitor back to.
 *
 * The confirmation's outcomes are GuestOrderConfirmation's own words —
 * 'confirmed', 'invalid', 'login', 'otherAccount' — so one enumeration reads
 * both. Read back with tryFrom() only: anything else in the address renders
 * nothing.
 */
enum StockNoticeResult: string
{
    public const PARAM = 'consho_stock_notice';

    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    // The article could be bought by the time the button was pressed.
    case Available = 'available';
    case Login = 'login';
    case Confirmed = 'confirmed';
    case Invalid = 'invalid';
    case OtherAccount = 'otherAccount';

    public static function fromQuery(mixed $value): self|null
    {
        return \is_string($value) ? self::tryFrom($value) : null;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

/**
 * Why a stock changed (Block G, G2) — tl_consho_stock_movement.reason.
 */
enum StockReason: string
{
    /** Booked at the purchase click (G4). */
    case Order = 'order';

    /** Back with a cancelled order (G9). */
    case Cancellation = 'cancellation';

    /** Back with a returned withdrawal line (G9). */
    case Return = 'return';

    /** Changed by hand in the back end (G3). */
    case Manual = 'manual';

    /** Goods received against a purchase list (G-7, E3). */
    case Receipt = 'receipt';
}

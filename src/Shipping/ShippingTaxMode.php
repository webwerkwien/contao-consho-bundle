<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * How shipping is taxed (C4). Proportional splits the net by the goods' value
 * over the rates in the cart — shipping as an ancillary service follows the
 * goods. Fixed uses the method's tax class. The class is mandatory in both
 * modes: with proportional it only converts a gross input.
 */
enum ShippingTaxMode: string
{
    case Proportional = 'proportional';
    case Fixed = 'fixed';
}

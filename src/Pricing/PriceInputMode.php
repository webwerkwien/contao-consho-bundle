<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * How an amount was typed into a mask (C22). Only an input aid: every
 * calculation reads the stored net.
 */
enum PriceInputMode: string
{
    case Gross = 'gross';
    case Net = 'net';
}

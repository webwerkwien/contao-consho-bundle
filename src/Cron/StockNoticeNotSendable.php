<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

/**
 * Why StockNoticeCron could not send, in its own words — the only messages it
 * logs verbatim (G). Anything else is logged by its class alone.
 */
final class StockNoticeNotSendable extends \RuntimeException
{
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

/**
 * A member with an unconfirmed address holds as many wishes as allowed
 * (StockNotices::UNCONFIRMED_LIMIT).
 */
final class StockNoticeLimitReached extends \RuntimeException
{
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Stock\SoldOutMarks;

/**
 * The net under the marks of "derzeit nicht verfügbar" (block Verfügbarkeit,
 * V1): every article anew, hourly.
 *
 * The triggers in StockBooking, StockAllocation, StockReturn and
 * CatalogRefreshListener cover the roads stock takes every day. A product
 * type switched to "Datei", a code deleted from the pool, a withdrawal of a
 * code not yet handed out — those come back here within the hour, the same
 * spirit as StockNoticeCron ("asked, not triggered").
 *
 * The listings are invalidated only when a mark moved.
 */
#[AsCronJob('hourly')]
final class SoldOutCron
{
    public function __construct(
        private readonly SoldOutMarks $marks,
        private readonly Connection $connection,
        private readonly CatalogCacheTags|null $catalog = null,
    ) {
    }

    public function __invoke(): void
    {
        /*
         * Between unpacking an update and contao:migrate the table does not
         * exist yet, and Contao's cron runs in that window too — measured on
         * c5 at the deploy of this block (2026-10-09 11:21): the exception
         * ended the whole cron run of that minute, every other job with it.
         */
        if (!$this->connection->createSchemaManager()->tablesExist(['tl_consho_sold_out'])) {
            return;
        }

        if ($this->marks->rebuild()) {
            $this->catalog?->invalidate([CatalogCacheTags::LISTINGS]);
        }
    }
}

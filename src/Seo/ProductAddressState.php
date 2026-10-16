<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * The parts of a product its address is built from, as stored at one moment.
 *
 * Taken before a save or a restore writes anything, and handed to
 * RedirectRecorder::recordProductAddress() once everything is written — see
 * there for why the comparison cannot run part by part against the database.
 *
 * Manufacturer and product type as ids, not aliases: which alias an id shows
 * depends on the language of the startpoint, and that is only known per
 * startpoint.
 */
final class ProductAddressState
{
    /**
     * @param array<string, string> $translations translated alias per language; '' for a row without one
     */
    public function __construct(
        public readonly int $pid,
        public readonly string $alias,
        public readonly string $sku,
        public readonly int $manufacturer,
        public readonly int $productType,
        public readonly array $translations,
    ) {
    }
}

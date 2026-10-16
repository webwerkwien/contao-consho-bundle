<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog\Read;

/**
 * A start point the catalogue cannot be read for — and why, as a code a
 * caller can report (Connect lists it as a finding at the feed).
 */
final class CatalogContextUnusable extends \RuntimeException
{
    public const NO_ROOT_PAGE = 'no_root_page';
    public const NOT_PUBLISHED = 'not_published';
    public const NO_SHOP = 'no_shop';
    public const NO_CURRENCY = 'no_currency';

    /*
     * Without a domain every address would be relative, or the host of
     * whoever happens to run the cron. A feed of links nobody can open is
     * worse than none.
     */
    public const NO_DOMAIN = 'no_domain';
    public const COUNTRY_NOT_SERVED = 'country_not_served';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, int $rootPageId, string $detail = ''): self
    {
        return new self($reason, \sprintf('Start point %d cannot be read as a catalogue: %s%s', $rootPageId, $reason, '' === $detail ? '' : ' ('.$detail.')'));
    }
}

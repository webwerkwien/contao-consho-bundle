<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog\Read;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RequestContext;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * A CatalogContext from a start point, read straight from the database —
 * no PageModel, no framework, so a cron and a console command get the same
 * answer as anything else.
 *
 * The start point's own `language` column is what counts, the value
 * PageModel::loadDetails() hands every page below it (ProductListController).
 */
final class CatalogContextFactory
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ShopLanguages $languages,
        /*
         * Where a start point names no domain — the usual setup of a site on
         * one domain — the application's own: `framework.router.default_uri`
         * on the console and in a cron, the request's host in a request.
         * Setting a domain on one start point only would make Contao prefer
         * it for that host and hide the others (measured on the test server,
         * 2026-10-10: every address answered 404).
         */
        #[Autowire(service: 'router.request_context')]
        private readonly RequestContext|null $requestContext = null,
    ) {
    }

    /**
     * @param string    $country delivery country; '' for the shop's own
     * @param list<int> $groups  member groups; empty for a guest
     *
     * @throws CatalogContextUnusable
     */
    public function forRootPage(int $rootPageId, string $country = '', array $groups = [], int $now = 0): CatalogContext
    {
        $root = $this->connection->fetchAssociative(
            "SELECT id, language, dns, useSSL, published, start, stop, consho_shop, consho_currency FROM tl_page WHERE id = ? AND type = 'root'",
            [$rootPageId],
        );

        if (false === $root) {
            throw CatalogContextUnusable::because(CatalogContextUnusable::NO_ROOT_PAGE, $rootPageId);
        }

        $now = $now > 0 ? $now : time();

        /*
         * A start point being prepared is no catalogue (Fable review
         * Connect-1, W2): its links answer 404, and a feed or the public JSON
         * would hand out a language nobody has released. Contao's own rule:
         * published, start and stop as strings, empty for none.
         */
        if (!(bool) $root['published'] || ('' !== (string) $root['start'] && (int) $root['start'] > $now) || ('' !== (string) $root['stop'] && (int) $root['stop'] <= $now)) {
            throw CatalogContextUnusable::because(CatalogContextUnusable::NOT_PUBLISHED, $rootPageId);
        }

        $shopId = (int) $root['consho_shop'];
        $shop = $shopId > 0 ? $this->connection->fetchAssociative('SELECT country, taxAtDestination, deliveryCountries FROM tl_consho_shop WHERE id = ?', [$shopId]) : false;

        if (false === $shop) {
            throw CatalogContextUnusable::because(CatalogContextUnusable::NO_SHOP, $rootPageId);
        }

        $currency = (string) $root['consho_currency'];

        if ('' === $currency) {
            throw CatalogContextUnusable::because(CatalogContextUnusable::NO_CURRENCY, $rootPageId);
        }

        $baseUrl = $this->baseUrl(trim((string) $root['dns']), (bool) $root['useSSL']);

        if (null === $baseUrl) {
            throw CatalogContextUnusable::because(CatalogContextUnusable::NO_DOMAIN, $rootPageId);
        }

        $shopCountry = strtoupper(trim((string) $shop['country']));
        $served = array_values(array_filter(array_map(
            static fn (mixed $code): string => strtoupper(trim((string) $code)),
            StringUtil::deserialize($shop['deliveryCountries'] ?? null, true),
        )));
        $country = strtoupper(trim($country));

        /*
         * No country asked: the shop's own — or, where it does not deliver
         * there, the first it does (review W2/H7: an Austrian shop selling
         * only to Germany had no readable catalogue at all).
         */
        if ('' === $country) {
            $country = [] === $served || \in_array($shopCountry, $served, true) ? $shopCountry : $served[0];
        }

        // The checkout would refuse the address (DeliveryCountryNotServed).
        if ([] !== $served && !\in_array($country, $served, true)) {
            throw CatalogContextUnusable::because(CatalogContextUnusable::COUNTRY_NOT_SERVED, $rootPageId, $country);
        }

        return new CatalogContext(
            shopId: $shopId,
            rootPageId: $rootPageId,
            language: (string) $root['language'],
            fallback: $this->languages->fallbackOf($shopId),
            currency: $currency,
            country: $country,
            shopCountry: $shopCountry,
            taxAtDestination: (bool) $shop['taxAtDestination'],
            deliveryCountries: $served,
            baseUrl: $baseUrl,
            groups: $groups,
            now: $now,
        );
    }

    /**
     * The start point's domain, else the application's; null where neither
     * is known — Symfony's default host is "localhost", which is no address
     * anyone outside can open.
     */
    private function baseUrl(string $dns, bool $useSsl): string|null
    {
        if ('' !== $dns) {
            return ($useSsl ? 'https://' : 'http://').$dns;
        }

        $host = $this->requestContext?->getHost() ?? '';

        if ('' === $host || 'localhost' === strtolower($host)) {
            return null;
        }

        $scheme = $this->requestContext?->getScheme() ?? 'https';
        $port = 'https' === $scheme ? $this->requestContext?->getHttpsPort() : $this->requestContext?->getHttpPort();
        $default = 'https' === $scheme ? 443 : 80;

        return $scheme.'://'.$host.(null === $port || $port === $default ? '' : ':'.$port).rtrim($this->requestContext?->getBaseUrl() ?? '', '/');
    }
}

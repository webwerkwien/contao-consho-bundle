<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A shop's start point without a domain (acceptance Abo-4): the links in every
 * mail sent from the cron or the console lead to `https://localhost/…`.
 *
 * In a request, a start point without a domain answers for the host that was
 * asked (DbalRedirectResolver), and the links follow it. On the command line
 * there is no request; the router takes the host of its RequestContext, which
 * is "localhost" unless the installation says otherwise. Those mails are the
 * subscription invoices (Abo-4) and "your digital content is ready" (F) —
 * mails the customer is meant to click.
 *
 * Consho does not guess the host. A wrong guess would send the customer
 * somewhere; saying so sends the operator to one of the two places that fix
 * it: the domain on the start point, or `framework.router.default_uri`. Either
 * setting silences the finding — the latter lands in the request context's
 * base URL, the older `router.request_context.host` in its host.
 *
 * A warning: the mail goes out and nothing fails, which is why it is not seen.
 */
final class StartPointDomainIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(param: 'router.request_context.base_url')]
        private readonly string $baseUrl = '',
        #[Autowire(param: 'router.request_context.host')]
        private readonly string $host = 'localhost',
    ) {
    }

    public function issues(): array
    {
        if ($this->contextHasHost()) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.id, s.title AS shop, p.title AS root FROM tl_page p
               JOIN tl_consho_shop s ON s.id = p.consho_shop
              WHERE p.type = 'root' AND p.dns = ''
              ORDER BY s.id, p.sorting, p.id",
        );

        $roots = [];
        $titles = [];

        foreach ($rows as $row) {
            $roots[(int) $row['id']][] = (string) $row['root'];
            $titles[(int) $row['id']] = (string) $row['shop'];
        }

        $issues = [];

        foreach ($roots as $shopId => $names) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::StartPointWithoutDomain, [
                'shop' => $titles[$shopId],
                'roots' => implode(', ', $names),
            ], $shopId);
        }

        return $issues;
    }

    private function contextHasHost(): bool
    {
        // A base URL without a host ("/shop") still yields localhost.
        $host = parse_url($this->baseUrl, \PHP_URL_HOST);

        if (\is_string($host) && '' !== $host) {
            return true;
        }

        return '' !== $this->host && 'localhost' !== strtolower($this->host);
    }
}

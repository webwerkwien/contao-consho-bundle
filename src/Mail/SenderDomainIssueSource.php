<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

use Contao\Idna;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * The sender's domain is none of the shop's domains (D-7, M5).
 *
 * Since D-1: a shipping mail once landed in a mailbox provider's junk. SPF and
 * DKIM passed; what stood out was a mail from one domain whose links all lead
 * to another — the pattern of the parcel phishing mail. On a test
 * installation that is how it is; in a real shop it is a setting worth a second
 * look. A note, not a warning: a shop may deliberately send from the
 * company's main domain.
 *
 * No DNS here — this runs on every back end page. SPF and DMARC are checked
 * on the test page of the mail configuration (MailTransportTestController).
 *
 * A start point without a domain answers for any host (DbalRedirectResolver),
 * so it says nothing about where the links go; a shop with no domain on any of
 * its start points is left alone. A subdomain counts as matching, either way
 * round, like relaxed DMARC alignment (DeliverabilityChecker).
 */
final class SenderDomainIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.title, t.senderAddress FROM tl_consho_shop s
             JOIN tl_consho_mail_transport t ON t.id = s.mailTransport
             ORDER BY s.id',
        );

        $issues = [];

        foreach ($shops as $shop) {
            $at = strrpos((string) $shop['senderAddress'], '@');

            if (false === $at) {
                continue;
            }

            // Punycode like tl_page.dns, which Contao stores encoded
            // (tl_page::checkDns) — shop@müller.at would otherwise never
            // match xn--mller-kva.at (D-7).
            $sender = strtolower(Idna::encode(substr((string) $shop['senderAddress'], $at + 1)));
            $domains = array_values(array_unique(array_map(
                static fn (string $dns): string => strtolower($dns),
                array_map(strval(...), $this->connection->fetchFirstColumn(
                    "SELECT dns FROM tl_page WHERE type = 'root' AND consho_shop = ? AND dns <> '' ORDER BY sorting",
                    [(int) $shop['id']],
                )),
            )));

            if ('' === $sender || [] === $domains) {
                continue;
            }

            foreach ($domains as $domain) {
                if ($sender === $domain || str_ends_with($domain, '.'.$sender) || str_ends_with($sender, '.'.$domain)) {
                    continue 2;
                }
            }

            $issues[] = new ConfigurationIssue(ConfigurationFinding::SenderDomainMismatch, [
                'shop' => (string) $shop['title'],
                'sender' => $sender,
                'domains' => implode(', ', $domains),
            ], (int) $shop['id']);
        }

        return $issues;
    }
}

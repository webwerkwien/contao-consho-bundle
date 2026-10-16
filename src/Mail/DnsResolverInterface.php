<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

/**
 * DNS behind a seam, so the deliverability check is testable.
 *
 * Without it every test would ask the real internet: slow, flaky, and unable to
 * describe the cases that matter — a domain WITHOUT an SPF record cannot be
 * conjured up on demand.
 */
interface DnsResolverInterface
{
    /**
     * @return list<string> TXT record values for the name, empty when there are none
     */
    public function txt(string $name): array;
}

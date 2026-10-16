<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

final class SystemDnsResolver implements DnsResolverInterface
{
    public function txt(string $name): array
    {
        /*
         * Silenced on purpose: dns_get_record emits a warning for a domain that
         * does not resolve, and "no such domain" is an ANSWER here, not an
         * error. The caller gets an empty list and reports a missing record.
         */
        $records = @dns_get_record($name, DNS_TXT);

        if (false === $records) {
            return [];
        }

        /*
         * `txt` rather than `entries`: a TXT record longer than 255 characters
         * is transmitted in chunks, and PHP exposes the chunks in `entries` and
         * the joined value in `txt`. Long SPF records are common, and matching
         * against a chunk would miss the "v=spf1" at the start of the whole.
         */
        return array_values(array_filter(array_map(
            static fn (array $record): string => (string) ($record['txt'] ?? ''),
            $records,
        )));
    }
}

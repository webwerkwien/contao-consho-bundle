<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

/**
 * Checks whether mail from this sender address is likely to arrive.
 *
 * It is not the transport that decides deliverability, it is domain alignment.
 * Google has required since 1 February 2024 that the domain in the From header
 * align with either the SPF or the DKIM domain, and non-compliant mail is no
 * longer merely filtered but rejected.
 *
 * That is why this checks the ADDRESS and not the transport type. Banning
 * sendmail:// would look decisive and catch nothing: sending as
 * bestellung@example-shop.at through a mail provider's account fails for exactly the same
 * reason, and passes any check that only looks at how mail leaves the server.
 *
 * DKIM cannot be verified generically: the record lives at
 * <selector>._domainkey.<domain> and the selector is not discoverable from the
 * address. The report says "not verifiable" rather than pretending it is
 * absent.
 */
final class DeliverabilityChecker
{
    public function __construct(private readonly DnsResolverInterface $dns)
    {
    }

    public function check(string $senderAddress, string $authenticatedAddress): DeliverabilityReport
    {
        $senderDomain = $this->domainOf($senderAddress);

        if (null === $senderDomain) {
            return new DeliverabilityReport(
                false,
                null,
                false,
                false,
                [DeliverabilityProblem::SenderAddressInvalid],
            );
        }

        $hasSpf = $this->hasRecordStartingWith($this->dns->txt($senderDomain), 'v=spf1');
        $hasDmarc = $this->hasDmarc($senderDomain);

        /*
         * An SMTP user name is often not an address at all — a hosting account
         * like w0123456 — and sendmail authenticates nothing. Both leave
         * nothing to compare, and inventing a mismatch there would be worse
         * than silence: an operator who is warned about a healthy setup stops
         * reading the warnings.
         */
        $authenticatedDomain = $this->domainOf($authenticatedAddress);
        $isAligned = null === $authenticatedDomain || $this->aligns($senderDomain, $authenticatedDomain);

        $problems = [];

        if (!$hasSpf) {
            $problems[] = DeliverabilityProblem::SpfMissing;
        }

        if (!$hasDmarc) {
            $problems[] = DeliverabilityProblem::DmarcMissing;
        }

        if (!$isAligned) {
            $problems[] = DeliverabilityProblem::AlignmentMismatch;
        }

        return new DeliverabilityReport(
            $hasSpf,
            null,
            $hasDmarc,
            $isAligned,
            $problems,
            $senderDomain,
            $authenticatedDomain,
        );
    }

    /**
     * DMARC is INHERITED, so one lookup is not enough.
     *
     * RFC 7489 queries _dmarc.<domain> and, finding no policy there, falls back
     * to the organisational domain: a subdomain without a record of its own is
     * covered by the parent. This walks up the labels to approximate that.
     *
     * Found against real DNS, not in a unit test:
     * an address at a subdomain was reported as having no DMARC, although the
     * parent domain carries p=quarantine and the subdomain inherits it. A
     * check that calls a healthy setup broken teaches operators to ignore it —
     * and then it is worth nothing on the day it is right.
     *
     * ⚠️ Two limits, both deliberate. The walk stops at two labels, because
     * _dmarc.wien would ask a registry about a policy for an entire top level
     * domain. And without the Public Suffix List "two labels" is an
     * approximation: for shop.example.co.uk it stops one level short of the
     * organisational domain. That errs towards a warning, which is the right
     * direction here.
     *
     * A wildcard TXT record — the same domain has one — answers _dmarc.<anything>
     * with something that is not a policy. Matching on the v=DMARC1 prefix is
     * what keeps that from being mistaken for one.
     */
    private function hasDmarc(string $domain): bool
    {
        $labels = explode('.', $domain);

        while (\count($labels) >= 2) {
            if ($this->hasRecordStartingWith($this->dns->txt('_dmarc.'.implode('.', $labels)), 'v=DMARC1')) {
                return true;
            }

            array_shift($labels);
        }

        return false;
    }

    /**
     * Relaxed alignment, the way DMARC defines it: a subdomain counts.
     *
     * Sending as shop@example.at through smtp.example.at is the everyday setup, and
     * a strict string comparison would report it as a fault. The leading dot is
     * what keeps `badexample.at` from passing as a subdomain of `example.at` —
     * without it, any domain merely ending in the same letters would align.
     *
     * ⚠️ This is deliberately not the full organisational-domain rule, which
     * needs the Public Suffix List. The consequence is that two subdomains of a
     * shared suffix — shop.example.co.uk and mail.example.co.uk — are not
     * recognised as aligned. That errs towards a warning rather than towards
     * false reassurance, which is the right direction for this check.
     */
    private function aligns(string $senderDomain, string $authenticatedDomain): bool
    {
        return $senderDomain === $authenticatedDomain
            || str_ends_with($authenticatedDomain, '.'.$senderDomain)
            || str_ends_with($senderDomain, '.'.$authenticatedDomain);
    }

    private function domainOf(string $address): string|null
    {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $at = strrpos($address, '@');

        return false === $at ? null : strtolower(substr($address, $at + 1));
    }

    /**
     * @param list<string> $records
     */
    private function hasRecordStartingWith(array $records, string $prefix): bool
    {
        foreach ($records as $record) {
            if (0 === stripos(trim($record), $prefix)) {
                return true;
            }
        }

        return false;
    }
}

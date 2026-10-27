<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Mail\DeliverabilityChecker;
use Webwerkwien\ContaoConshoBundle\Mail\DeliverabilityProblem;
use Webwerkwien\ContaoConshoBundle\Mail\DnsResolverInterface;

class DeliverabilityCheckerTest extends TestCase
{
    public function testReportsSpfAndDmarcWhenBothArePresent(): void
    {
        $checker = $this->checkerWith([
            'example.net' => ['v=spf1 include:_spf.example.com ~all'],
            '_dmarc.example.net' => ['v=DMARC1; p=quarantine; rua=mailto:d@example.net'],
        ]);

        $report = $checker->check('shop@example.net', 'shop@example.net');

        self::assertTrue($report->hasSpf);
        self::assertTrue($report->hasDmarc);
        self::assertTrue($report->isAligned);
        self::assertSame([], $report->problems);
    }

    public function testReportsAMissingSpfRecord(): void
    {
        $checker = $this->checkerWith(['_dmarc.example.net' => ['v=DMARC1; p=none']]);

        $report = $checker->check('shop@example.net', 'shop@example.net');

        self::assertFalse($report->hasSpf);
        self::assertContains(DeliverabilityProblem::SpfMissing, $report->problems);
    }

    /**
     * A domain typically carries several TXT records — site verification,
     * Microsoft tenant proof. None of them is an SPF record.
     */
    public function testIgnoresUnrelatedTxtRecords(): void
    {
        $checker = $this->checkerWith([
            'example.net' => ['google-site-verification=abc', 'MS=ms12345'],
        ]);

        self::assertFalse($this->checkerWith([])->check('shop@example.net', '')->hasSpf);
        self::assertFalse($checker->check('shop@example.net', 'shop@example.net')->hasSpf);
    }

    /**
     * Sending as bestellung@example-shop.at through a mail provider's account. This is the
     * case a ban on sendmail:// would let straight through — which is why the
     * check hangs on alignment and not on the transport type.
     */
    public function testDetectsAMismatchBetweenSenderAndAuthenticatedDomain(): void
    {
        $checker = $this->checkerWith([
            'example-shop.at' => ['v=spf1 -all'],
            '_dmarc.example-shop.at' => ['v=DMARC1; p=reject'],
        ]);

        $report = $checker->check('bestellung@example-shop.at', 'someone@mailprovider.example');

        self::assertFalse($report->isAligned);
        self::assertContains(DeliverabilityProblem::AlignmentMismatch, $report->problems);
    }

    /**
     * Relaxed alignment, the way DMARC itself defines it: a subdomain of the
     * sender domain counts as aligned. Without this, the everyday setup of
     * sending as shop@example.at through smtp.example.at would be reported as a
     * problem — and a check that cries wolf is a check nobody reads.
     */
    public function testASubdomainOfTheSenderDomainCountsAsAligned(): void
    {
        $checker = $this->checkerWith([
            'example.at' => ['v=spf1 -all'],
            '_dmarc.example.at' => ['v=DMARC1; p=none'],
        ]);

        self::assertTrue($checker->check('shop@example.at', 'noreply@mail.example.at')->isAligned);
        self::assertTrue($checker->check('shop@mail.example.at', 'noreply@example.at')->isAligned);
    }

    /**
     * And the near miss that must NOT pass: a domain merely ending in the same
     * letters is a different domain.
     */
    public function testALookalikeDomainIsNotAligned(): void
    {
        $checker = $this->checkerWith(['example.at' => ['v=spf1 -all']]);

        self::assertFalse($checker->check('shop@example.at', 'a@badexample.at')->isAligned);
    }

    /**
     * sendmail has no authenticated account, and an SMTP user name is often not
     * an address at all but a hosting account like w0123456. There is then
     * nothing to compare, and inventing a mismatch would be worse than silence.
     */
    public function testAnEmptyOrNonAddressAccountCountsAsAligned(): void
    {
        $checker = $this->checkerWith([
            'example.net' => ['v=spf1 a -all'],
            '_dmarc.example.net' => ['v=DMARC1; p=none'],
        ]);

        self::assertTrue($checker->check('shop@example.net', '')->isAligned);
        self::assertTrue($checker->check('shop@example.net', 'w0123456')->isAligned);
    }

    /**
     * DMARC is inherited. RFC 7489 looks up _dmarc.<domain> and, finding
     * nothing, falls back to the organisational domain — a subdomain without
     * its own record is covered by the parent policy.
     *
     * Without that fallback, an address at a subdomain is reported against
     * real DNS as having no DMARC although the parent domain carries
     * p=quarantine and the subdomain inherits it. A check that reports a
     * healthy setup as broken teaches operators to ignore it.
     */
    public function testDmarcOnTheParentDomainCountsForASubdomain(): void
    {
        $checker = $this->checkerWith([
            'web.example.net' => ['v=spf1 mx -all'],
            '_dmarc.example.net' => ['v=DMARC1; p=quarantine'],
        ]);

        self::assertTrue($checker->check('shop@web.example.net', '')->hasDmarc);
    }

    /**
     * A wildcard TXT record answers _dmarc.<anything> with something that is
     * not a DMARC record. The domain from the finding above does exactly that. It must not be mistaken
     * for a policy.
     */
    public function testAWildcardAnsweringWithSomethingElseIsNotADmarcRecord(): void
    {
        $checker = $this->checkerWith([
            '_dmarc.web.example.net' => ['v=spf1 mx -all'],
            '_dmarc.example.net' => ['v=spf1 mx -all'],
        ]);

        self::assertFalse($checker->check('shop@web.example.net', '')->hasDmarc);
    }

    /**
     * The walk up stops at two labels. Querying _dmarc.wien would ask the
     * registry about a policy for an entire top level domain — never the
     * answer to this question, and a lookup nobody asked for.
     */
    public function testTheWalkUpStopsAtTheSecondLabel(): void
    {
        $resolver = $this->createMock(DnsResolverInterface::class);
        $asked = [];
        $resolver->method('txt')->willReturnCallback(
            static function (string $name) use (&$asked): array {
                $asked[] = $name;

                return [];
            },
        );

        (new DeliverabilityChecker($resolver))->check('shop@a.b.example.net', '');

        self::assertContains('_dmarc.a.b.example.net', $asked);
        self::assertContains('_dmarc.example.net', $asked);
        self::assertNotContains('_dmarc.wien', $asked);
    }

    /**
     * An honest limitation, stated rather than faked: DKIM lives at
     * <selector>._domainkey.<domain> and the selector is not discoverable.
     * null, not false — "cannot be checked" is not "absent". The same
     * distinction VIES forced in A-5.
     */
    public function testCannotVerifyDkimWithoutASelector(): void
    {
        $report = $this->checkerWith(['example.net' => ['v=spf1 -all']])->check('shop@example.net', '');

        self::assertNull($report->hasDkim);
    }

    public function testAMalformedSenderAddressIsReportedInsteadOfCrashing(): void
    {
        $report = $this->checkerWith([])->check('not-an-address', '');

        self::assertContains(DeliverabilityProblem::SenderAddressInvalid, $report->problems);
    }

    public function testTheReportNamesTheDomainsItJudged(): void
    {
        $report = $this->checkerWith([])->check('shop@example.net', 'a@mailprovider.example');

        self::assertSame('example.net', $report->senderDomain);
        self::assertSame('mailprovider.example', $report->authenticatedDomain);
    }

    /**
     * @param array<string, list<string>> $records
     */
    private function checkerWith(array $records): DeliverabilityChecker
    {
        $resolver = $this->createMock(DnsResolverInterface::class);
        $resolver->method('txt')->willReturnCallback(
            static fn (string $name): array => $records[$name] ?? [],
        );

        return new DeliverabilityChecker($resolver);
    }
}

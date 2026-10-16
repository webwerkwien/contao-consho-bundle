<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Bridge\MicrosoftGraph\Transport\MicrosoftGraphApiTransport;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Webwerkwien\ContaoConshoBundle\Mail\SecretCipher;
use Webwerkwien\ContaoConshoBundle\Mail\TransportDsnBuilder;
use Webwerkwien\ContaoConshoBundle\Mail\UnsupportedTransportType;

class TransportDsnBuilderTest extends TestCase
{
    private TransportDsnBuilder $builder;

    private SecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new SecretCipher(str_repeat('k', 32));
        $this->builder = new TransportDsnBuilder($this->cipher);
    }

    public function testBuildsAnSmtpDsn(): void
    {
        self::assertSame(
            'smtp://shop%40example.org:secret@smtp.example.org:587',
            $this->builder->build($this->smtpRow()),
        );
    }

    /**
     * A password containing "@" or ":" would otherwise tear the DSN apart — and
     * the resulting error looks like a wrong host, not like a quoting problem.
     */
    public function testEncodesSpecialCharactersInCredentials(): void
    {
        $dsn = $this->builder->build($this->smtpRow(['password' => $this->cipher->encrypt('p@ss:w/rd')]));

        self::assertStringContainsString('p%40ss%3Aw%2Frd', $dsn);
    }

    /**
     * The three values of the encryption field have to reach the DSN, or the
     * operator sets a control that does nothing. Symfony 7.4 decides it by
     * scheme and one option (EsmtpTransportFactory): smtps forces implicit TLS,
     * smtp negotiates STARTTLS, and auto_tls=false switches it off entirely.
     */
    public function testSslUsesTheSmtpsScheme(): void
    {
        $dsn = $this->builder->build($this->smtpRow(['encryption' => 'ssl', 'port' => 465]));

        self::assertStringStartsWith('smtps://', $dsn);
        self::assertStringEndsWith(':465', $dsn);
    }

    public function testTlsNegotiatesAndAddsNoOption(): void
    {
        $dsn = $this->builder->build($this->smtpRow(['encryption' => 'tls']));

        self::assertStringStartsWith('smtp://', $dsn);
        self::assertStringNotContainsString('auto_tls', $dsn);
    }

    public function testNoEncryptionSwitchesAutoTlsOff(): void
    {
        $dsn = $this->builder->build($this->smtpRow(['encryption' => 'none']));

        self::assertStringContainsString('auto_tls=false', $dsn);
    }

    /**
     * A relay on the same host often wants no authentication at all. An empty
     * user and password would otherwise produce "smtp://:@host", which Symfony
     * reads as credentials that happen to be empty.
     */
    public function testATransportWithoutCredentialsCarriesNoUserInfo(): void
    {
        $dsn = $this->builder->build($this->smtpRow(['username' => '', 'password' => '']));

        self::assertSame('smtp://smtp.example.org:587', $dsn);
    }

    public function testBuildsAGraphDsn(): void
    {
        $dsn = $this->builder->build([
            'type' => 'graph',
            'tenantId' => 'tenant-1',
            'clientId' => 'client-1',
            'clientSecret' => $this->cipher->encrypt('shhh'),
        ]);

        self::assertSame('microsoftgraph+api://client-1:shhh@default?tenantId=tenant-1', $dsn);
    }

    /**
     * 🔴 Asked of Symfony itself, not of a string (C-9, 2026-09-25).
     *
     * Until then the Graph DSN was "microsoft-graph+api://…?tenant=" and the
     * test above checked exactly that prefix — so it guarded the mistake. The
     * bridge only knows "microsoftgraph+api" and reads "tenantId"; no Graph
     * mail could ever have left. Every type the builder offers must now be
     * turned into a transport by the factories that will really send it.
     */
    public function testEveryDsnIsUnderstoodBySymfonyMailer(): void
    {
        // With a client, as MailTransportFactory passes one: without it the
        // Graph factory dies of a TypeError in TokenManager.
        $graph = Transport::fromDsn($this->builder->build([
            'type' => 'graph',
            'tenantId' => 'tenant-1',
            'clientId' => 'client-1',
            'clientSecret' => $this->cipher->encrypt('shhh'),
        ]), null, new MockHttpClient());

        self::assertInstanceOf(MicrosoftGraphApiTransport::class, $graph);
        self::assertInstanceOf(EsmtpTransport::class, Transport::fromDsn($this->builder->build($this->smtpRow())));
        self::assertInstanceOf(SendmailTransport::class, Transport::fromDsn($this->builder->build(['type' => 'sendmail'])));
    }

    public function testBuildsASendmailDsn(): void
    {
        self::assertSame('sendmail://default', $this->builder->build(['type' => 'sendmail']));
    }

    public function testRefusesNativeTransport(): void
    {
        $this->expectException(UnsupportedTransportType::class);

        $this->builder->build(['type' => 'native']);
    }

    public function testRefusesAnUnknownType(): void
    {
        $this->expectException(UnsupportedTransportType::class);

        $this->builder->build(['type' => 'carrier-pigeon']);
    }

    /**
     * If the key in .env was rotated the password can no longer be decrypted.
     * Connecting without it would produce an authentication error that looks
     * exactly like a wrong password — and nobody would think of the key.
     */
    public function testAnUndecryptablePasswordIsRefusedRatherThanSentEmpty(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->builder->build($this->smtpRow(['password' => 'not-valid-ciphertext']));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function smtpRow(array $overrides = []): array
    {
        return [
            'type' => 'smtp',
            'host' => 'smtp.example.org',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'shop@example.org',
            'password' => $this->cipher->encrypt('secret'),
            ...$overrides,
        ];
    }
}

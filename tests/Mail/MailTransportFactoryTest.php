<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Bridge\MicrosoftGraph\Transport\MicrosoftGraphApiTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactory;
use Webwerkwien\ContaoConshoBundle\Mail\SecretCipher;
use Webwerkwien\ContaoConshoBundle\Mail\TransportDsnBuilder;

class MailTransportFactoryTest extends TestCase
{
    private SecretCipher $cipher;

    private MailTransportFactory $factory;

    protected function setUp(): void
    {
        $this->cipher = new SecretCipher(str_repeat('k', 32));
        $this->factory = new MailTransportFactory(new TransportDsnBuilder($this->cipher), new MockHttpClient());
    }

    /**
     * Without a limit the buyer waits for default_socket_timeout after the
     * purchase click — usually 60 seconds — for a mail server that does not answer.
     */
    public function testSmtpBekommtEinZeitlimitVonZehnSekunden(): void
    {
        $transport = $this->factory->create([
            'type' => 'smtp',
            'host' => 'smtp.example.org',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
        ]);

        self::assertInstanceOf(EsmtpTransport::class, $transport);

        $stream = $transport->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(10.0, $stream->getTimeout());
    }

    public function testGraphEntstehtMitHttpClient(): void
    {
        $transport = $this->factory->create([
            'type' => 'graph',
            'tenantId' => 'tenant-1',
            'clientId' => 'client-1',
            'clientSecret' => $this->cipher->encrypt('shhh'),
        ]);

        self::assertInstanceOf(MicrosoftGraphApiTransport::class, $transport);
    }

    public function testSendmailBleibtSendmail(): void
    {
        self::assertInstanceOf(SendmailTransport::class, $this->factory->create(['type' => 'sendmail']));
    }
}

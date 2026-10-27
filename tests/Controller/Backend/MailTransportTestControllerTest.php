<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\MailTransportTestController;
use Webwerkwien\ContaoConshoBundle\Mail\DeliverabilityChecker;
use Webwerkwien\ContaoConshoBundle\Mail\DnsResolverInterface;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * "Testmail senden" (send test mail, C-9). Had it existed, the password
 * error would have shown up without a test purchase.
 */
class MailTransportTestControllerTest extends TestCase
{
    private Connection $connection;

    private TestMailTransport $transport;

    /** @var list<array{string, string}> */
    private array $messages = [];

    private string $pending = '';

    /** @var array<string, list<string>> TXT records by name */
    private array $dns = ['example.org' => ['v=spf1 -all'], '_dmarc.example.org' => ['v=DMARC1; p=reject']];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_mail_transport');
        $this->connection->insert('tl_consho_mail_transport', ['id' => 1, 'title' => 'Testversand', 'senderAddress' => 'shop@example.org', 'senderName' => 'Mein Shop']);
        $this->transport = new TestMailTransport();

        require __DIR__.'/../../../contao/languages/de/tl_consho_mail_transport.php';
        require __DIR__.'/../../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDasFormularIstMitDerAdresseDesBenutzersVorbelegt(): void
    {
        $html = $this->controller(Request::create('/contao?do=consho_mail&key=testMail&id=1'))($this->dc(1));

        self::assertStringContainsString('value="redakteur@example.org"', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
        self::assertStringContainsString('Absender ist shop@example.org', $html);
        self::assertSame([], $this->transport->sent);
    }

    /**
     * The test mail worked, but there was no visual confirmation in the back
     * end. The page redirects to itself and is not a Contao list — it has to
     * output the message itself.
     */
    /**
     * D-7, M5: the check from Block A had been built and was never called.
     * Now it sits next to the technical test.
     */
    public function testDieZustellbarkeitStehtNebenDemTest(): void
    {
        $html = $this->controller(Request::create('/contao?do=consho_mail&key=testMail&id=1'))($this->dc(1));

        self::assertStringContainsString('Zustellbarkeit: shop@example.org', $html);
        self::assertStringContainsString('SPF und DMARC sind eingetragen', $html);
        self::assertStringContainsString('_domainkey.example.org', $html, 'DKIM: nicht prüfbar, gesagt statt verschwiegen');
    }

    public function testFehlendeEintraegeUndFremdeAnmeldung(): void
    {
        $this->dns = [];
        $this->connection->update('tl_consho_mail_transport', ['username' => 'konto@mailprovider.example'], ['id' => 1]);

        $html = $this->controller(Request::create('/contao?do=consho_mail&key=testMail&id=1'))($this->dc(1));

        self::assertStringContainsString('Für example.org gibt es keinen SPF-Eintrag', $html);
        self::assertStringContainsString('Für example.org gibt es keinen DMARC-Eintrag', $html);
        self::assertStringContainsString('(mailprovider.example)', $html);
        self::assertStringNotContainsString('SPF und DMARC sind eingetragen', $html);
    }

    public function testSendmailMeldetKeineFremdeAnmeldung(): void
    {
        $this->connection->update('tl_consho_mail_transport', ['type' => 'sendmail', 'username' => 'konto@mailprovider.example'], ['id' => 1]);

        $html = $this->controller(Request::create('/contao?do=consho_mail&key=testMail&id=1'))($this->dc(1));

        self::assertStringContainsString('SPF und DMARC sind eingetragen', $html, 'sendmail meldet sich nirgends an');
    }

    public function testDieSeiteZeigtDieGespeicherteMeldung(): void
    {
        $this->pending = '<div class="tl_message"><p class="tl_confirm">Angenommen</p></div>';

        $html = $this->controller(Request::create('/contao?do=consho_mail&key=testMail&id=1'))($this->dc(1));

        self::assertStringContainsString('<p class="tl_confirm">Angenommen</p>', $html);
    }

    public function testPerPostGehtDieTestmailUeberDieKonfiguration(): void
    {
        $target = $this->redirectOf(fn () => $this->controller($this->post('empfang@example.net'))($this->dc(1)));

        self::assertSame('http://localhost/contao?do=consho_mail&id=1&key=testMail', $target);
        self::assertCount(1, $this->transport->sent);
        self::assertSame('empfang@example.net', $this->transport->sent[0]->getTo()[0]->getAddress());
        self::assertSame('shop@example.org', $this->transport->sent[0]->getFrom()[0]->getAddress());
        self::assertSame('addConfirmation', $this->messages[0][0]);
    }

    /**
     * Nachlese stage 2: on a shop the label arrived as "&#40;…&#41;" in subject
     * and text as soon as the mask had been saved once — Contao's input
     * encoding. Operator texts go out decoded.
     */
    public function testBezeichnungUndAbsenderKommenDekodiert(): void
    {
        $this->connection->update('tl_consho_mail_transport', ['title' => 'Versand &#40;Hermes&#41;', 'senderName' => 'Müller &amp; Co'], ['id' => 1]);

        $this->redirectOf(fn () => $this->controller($this->post('empfang@example.net'))($this->dc(1)));

        $mail = $this->transport->sent[0];
        self::assertSame('Consho: Testmail — Versand (Hermes)', $mail->getSubject());
        self::assertStringContainsString('„Versand (Hermes)"', (string) $mail->getTextBody());
        self::assertSame('Müller & Co', $mail->getFrom()[0]->getName());

        $html = $this->controller(Request::create('/contao?do=consho_mail&key=testMail&id=1'))($this->dc(1));
        self::assertStringNotContainsString('&amp;#40;', $html, 'auf der Seite nicht doppelt kodiert');
    }

    /**
     * That is exactly what it is for: the server's answer in the message.
     */
    public function testDieAntwortDesServersStehtInDerMeldung(): void
    {
        $this->transport->fail = true;

        $this->redirectOf(fn () => $this->controller($this->post('empfang@example.net'))($this->dc(1)));

        self::assertSame('addError', $this->messages[0][0]);
        self::assertStringContainsString('535 5.7.8 Error: authentication failed', $this->messages[0][1]);
    }

    /**
     * Contao outputs messages unfiltered as HTML, and the server's answer is
     * foreign text (with Graph an HTTP body).
     */
    public function testDieAntwortDesServersWirdEscaped(): void
    {
        $this->transport->failWith = 'Unexpected response "<img src=x onerror=alert(1)>"';

        $this->redirectOf(fn () => $this->controller($this->post('empfang@example.net'))($this->dc(1)));

        self::assertStringNotContainsString('<img', $this->messages[0][1]);
        self::assertStringContainsString('&lt;img', $this->messages[0][1]);
    }

    public function testEineUngueltigeAdresseWirdNichtVersucht(): void
    {
        $this->redirectOf(fn () => $this->controller($this->post('kein-at-zeichen'))($this->dc(1)));

        self::assertSame([], $this->transport->sent);
        self::assertSame([['addError', '„kein-at-zeichen" ist keine gültige E-Mail-Adresse.']], $this->messages);
    }

    public function testOhneLeserechtNichts(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller($this->post('empfang@example.net'), granted: false)($this->dc(1));
    }

    private function post(string $recipient): Request
    {
        return Request::create('/contao?do=consho_mail&key=testMail&id=1', 'POST', ['recipient' => $recipient]);
    }

    private function redirectOf(\Closure $action): string
    {
        try {
            $action();
        } catch (RedirectResponseException $e) {
            return (string) $e->getResponse()->headers->get('Location');
        }

        self::fail('Es wurde nicht zurückgeleitet.');
    }

    private function controller(Request $request, bool $granted = true): MailTransportTestController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $user = $this->createMock(BackendUser::class);
        $user->method('__get')->willReturnCallback(static fn (string $key): mixed => 'email' === $key ? 'redakteur@example.org' : null);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($granted);
        $security->method('getUser')->willReturn($user);

        $messages = &$this->messages;
        $pending = $this->pending;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages, $pending): string {
            if ('generate' === $name) {
                return $pending;
            }

            $messages[] = [$name, (string) $args[0]];

            return '';
        });

        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_mail');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => Message::class === $class ? $message : $system,
        );

        $transport = $this->transport;
        $factory = new class($transport) implements MailTransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function create(array $row): TransportInterface
            {
                return $this->transport;
            }
        };

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);

        $records = $this->dns;
        $dns = new class($records) implements DnsResolverInterface {
            /**
             * @param array<string, list<string>> $records
             */
            public function __construct(private readonly array $records)
            {
            }

            public function txt(string $name): array
            {
                return $this->records[$name] ?? [];
            }
        };

        return new MailTransportTestController($this->connection, $security, $framework, $requests, $factory, $twig, new DeliverabilityChecker($dns));
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

final class TestMailTransport extends AbstractTransport
{
    /** @var list<Email> */
    public array $sent = [];

    public bool $fail = false;

    public string $failWith = '';

    public function __toString(): string
    {
        return 'test://';
    }

    protected function doSend(SentMessage $message): void
    {
        if ('' !== $this->failWith) {
            throw new TransportException($this->failWith);
        }

        if ($this->fail) {
            throw new TransportException('Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed: (reason unavailable)".');
        }

        $email = $message->getOriginalMessage();
        \assert($email instanceof Email);
        $this->sent[] = $email;
    }
}

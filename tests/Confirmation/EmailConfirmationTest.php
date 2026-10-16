<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Confirmation;

use Contao\CoreBundle\OptIn\OptInInterface;
use Contao\CoreBundle\OptIn\OptInTokenAlreadyConfirmedException;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Contao\CoreBundle\OptIn\OptInTokenNoLongerValidException;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationStatus;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ErrorSpyLogger;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\FakeConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\SpyTransport;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * D-4, K3a — die Bestätigungsmail als Dienst für mehrere Stellen. Token über
 * Contaos Opt-in, Mail über den Transport des Shops; der Link bestätigt
 * nichts, erst confirm() (aus einem POST).
 */
final class EmailConfirmationTest extends TestCase
{
    private FakeOptIn $optIn;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    private FakeConfirmationSource $source;

    protected function setUp(): void
    {
        $this->optIn = new FakeOptIn();
        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();
        $this->source = new FakeConfirmationSource();
    }

    public function testDieMailGehtUeberDenShopMitDemLinkZurSeite(): void
    {
        self::assertTrue($this->service()->request(ConfirmationPurpose::GuestOrders, 1, 'kundin@example.org', 'Erika Muster', 'de', ['tl_member' => [5]], 'https://shop.test/konto?x=1'), implode("\n", $this->logger->errors));

        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertSame('Bitte bestätigen Sie Ihre E-Mail-Adresse', $mail->getSubject());
        self::assertSame('shop@web.example.net', $mail->getFrom()[0]->getAddress(), 'Absender des Shops, nicht des Systems');
        self::assertSame('kundin@example.org', $mail->getTo()[0]->getAddress());

        $token = $this->optIn->tokens[0];
        self::assertSame('cogo', $token->prefix);
        self::assertSame(['tl_member' => [5]], $token->getRelatedRecords());

        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('https://shop.test/konto?x=1&amp;consho_confirm='.$token->getIdentifier(), $html);
        self::assertStringContainsString('Guten Tag Erika Muster,', $html);
        self::assertStringContainsString('kundin@example.org', $html);
        self::assertStringContainsString('Der Link gilt 24 Stunden.', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertStringNotContainsString('CONSHO.', (string) $mail->getSubject());
    }

    public function testAuchEnglischVollstaendig(): void
    {
        $this->service()->request(ConfirmationPurpose::GuestOrders, 1, 'k@example.org', '', 'en', [], 'https://shop.test/account');

        $html = (string) $this->transport->sent[0]->getHtmlBody();
        self::assertStringContainsString('Hello,', $html, 'ohne Namen keine Anrede mit leerem Namen');
        self::assertStringContainsString('Confirm address', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    public function testDasOeffnenBestaetigtNichtErstConfirm(): void
    {
        $service = $this->service();
        $service->request(ConfirmationPurpose::GuestOrders, 1, 'k@example.org', '', 'de', ['tl_member' => [5]], 'https://shop.test/');
        $id = $this->optIn->tokens[0]->getIdentifier();

        $check = $service->check(ConfirmationPurpose::GuestOrders, $id);
        self::assertSame(ConfirmationStatus::Pending, $check->status);
        self::assertSame('k@example.org', $check->email);
        self::assertSame([5], $check->ids('tl_member'));
        self::assertFalse($this->optIn->tokens[0]->isConfirmed(), 'check() bestätigt nichts');

        self::assertSame(ConfirmationStatus::Confirmed, $service->confirm(ConfirmationPurpose::GuestOrders, $id)->status);
        self::assertSame(ConfirmationStatus::Confirmed, $service->confirm(ConfirmationPurpose::GuestOrders, $id)->status, 'zweimal ist bestätigt');
        self::assertSame(ConfirmationStatus::Confirmed, $service->check(ConfirmationPurpose::GuestOrders, $id)->status);
    }

    public function testFremdeAbgelaufeneUndUnbekannteTokensGeltenNicht(): void
    {
        $service = $this->service();
        $this->optIn->tokens[] = new FakeOptInToken('reg-abc', 'reg', 'k@example.org', []);
        $this->optIn->tokens[] = $expired = new FakeOptInToken('cogo-old', 'cogo', 'k@example.org', ['tl_member' => [5]]);
        $expired->valid = false;

        self::assertSame(ConfirmationStatus::Invalid, $service->check(ConfirmationPurpose::GuestOrders, 'reg-abc')->status, 'Contaos Registrierungs-Token ist keiner von uns');
        self::assertSame(ConfirmationStatus::Invalid, $service->confirm(ConfirmationPurpose::GuestOrders, 'reg-abc')->status);
        self::assertSame(ConfirmationStatus::Invalid, $service->check(ConfirmationPurpose::GuestOrders, 'cogo-old')->status);
        self::assertSame(ConfirmationStatus::Invalid, $service->confirm(ConfirmationPurpose::GuestOrders, 'cogo-old')->status);
        self::assertSame(ConfirmationStatus::Invalid, $service->check(ConfirmationPurpose::GuestOrders, 'cogo-gibtsnicht')->status);
        self::assertSame(ConfirmationStatus::Invalid, $service->check(ConfirmationPurpose::GuestOrders, '')->status);
    }

    public function testEinAusgefallenerServerWirftNicht(): void
    {
        $this->transport->fail = true;

        self::assertFalse($this->service()->request(ConfirmationPurpose::GuestOrders, 1, 'k@example.org', '', 'de', [], 'https://shop.test/'));
        self::assertNotEmpty($this->logger->errors);
    }

    public function testOhneMailKonfigurationKeinVersuch(): void
    {
        $this->source->shopRow['transport'] = null;

        self::assertFalse($this->service()->request(ConfirmationPurpose::GuestOrders, 1, 'k@example.org', '', 'de', [], 'https://shop.test/'));
        self::assertSame([], $this->optIn->tokens, 'kein Token, wenn keine Mail gehen kann');
    }

    private function service(): EmailConfirmation
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $translator = new ContaoLikeTranslator();
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        $factory = new class($this->transport) implements MailTransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function create(array $row): TransportInterface
            {
                return $this->transport;
            }
        };

        return new EmailConfirmation($this->optIn, $this->source, $factory, new MailRenderer($twig, $translator), $this->logger);
    }
}

final class FakeOptIn implements OptInInterface
{
    /** @var list<FakeOptInToken> */
    public array $tokens = [];

    public function create(string $prefix, string $email, array $related, \DateTimeInterface|null $validUntil = null): OptInTokenInterface
    {
        return $this->tokens[] = new FakeOptInToken($prefix.'-'.bin2hex(random_bytes(6)), $prefix, $email, $related);
    }

    public function find(string $identifier): OptInTokenInterface|null
    {
        foreach ($this->tokens as $token) {
            if ($token->getIdentifier() === $identifier) {
                return $token;
            }
        }

        return null;
    }

    public function purgeTokens(): void
    {
    }
}

final class FakeOptInToken implements OptInTokenInterface
{
    public bool $valid = true;

    private bool $confirmed = false;

    /**
     * @param array<string, list<int>> $related
     */
    public function __construct(
        private readonly string $identifier,
        public readonly string $prefix,
        private readonly string $email,
        private readonly array $related,
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function confirm(): void
    {
        if ($this->confirmed) {
            throw new OptInTokenAlreadyConfirmedException();
        }

        if (!$this->valid) {
            throw new OptInTokenNoLongerValidException();
        }

        $this->confirmed = true;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function send(string|null $subject = null, string|null $text = null): void
    {
    }

    public function hasBeenSent(): bool
    {
        return false;
    }

    public function getRelatedRecords(): array
    {
        return $this->related;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\PaymentChangeMail;
use Webwerkwien\ContaoConshoBundle\Notification\RecipientResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

// The fakes of the confirmation — the same source, transport and translator.
require_once __DIR__.'/OrderConfirmationTest.php';

/**
 * 7.5 Schritt 3 (C-10, E4): Nach dem Wechsel der Zahlungsart eine Mail an den
 * Kunden mit Storno und neuer Rechnung, und eine Kopie an den Betreiber.
 */
class PaymentChangeMailTest extends TestCase
{
    private FakeConfirmationSource $source;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    protected function setUp(): void
    {
        $this->source = new FakeConfirmationSource();
        $this->source->order['paymentMethodTitle'] = 'Vorkasse Bank B';
        $this->source->documents = [
            15 => ['id' => 15, 'documentNumber' => 'ST-00002', 'payload' => ['cancels' => ['number' => 'RE-00014', 'issuedAt' => '1758700000']]],
            16 => ['id' => 16, 'documentNumber' => 'RE-00016', 'payload' => ['payment' => [
                'instructions' => '<p>Neue Bank.</p>',
                'details' => ['accountHolder' => 'Muster GmbH', 'iban' => 'AT48 3200 0000 1234 5864', 'bic' => '', 'bankName' => ''],
            ]]],
        ];
        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();
    }

    public function testDieKundinBekommtNeueZahlungStornoUndNeueRechnung(): void
    {
        self::assertTrue($this->mail()->send(7, 15, 16, 'https://shop.test/bestellung?consho_order=abc'));

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('kundin@example.org', $mail->getTo()[0]->getAddress());
        self::assertSame('Neue Zahlungsart für Ihre Bestellung WEB-00054', $mail->getSubject());
        self::assertStringContainsString('Vorkasse Bank B', $html);
        self::assertStringContainsString('Die Rechnung RE-00014 ist damit storniert, die Stornorechnung ST-00002 liegt bei.', $html);
        self::assertStringContainsString('Die neue Rechnung RE-00016', $html);

        // Die Bank der NEUEN Rechnung, nicht die der stornierten — und der
        // Verwendungszweck ist die neue Nummer.
        self::assertStringContainsString('AT48 3200 0000 1234 5864', $html);
        self::assertStringNotContainsString('AT61 1904 3002 3457 3201', $html);
        self::assertMatchesRegularExpression('#Verwendungszweck</th><td[^>]*>RE-00016</td>#', $html);
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', $html);
        self::assertStringNotContainsString('CONSHO.', $html);

        self::assertSame(['ST-00002.pdf', 'RE-00016.pdf'], array_map(static fn ($p): string => (string) $p->getFilename(), $mail->getAttachments()));
    }

    public function testDerBetreiberBekommtEineKopieMitBeidenBelegen(): void
    {
        $this->mail()->send(7, 15, 16, 'https://shop.test/x');

        $copy = $this->transport->sent[1];

        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertSame('Zahlungsart geändert: Bestellung WEB-00054', $copy->getSubject());
        self::assertStringContainsString('RE-00014', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
        self::assertCount(2, $copy->getAttachments());
    }

    public function testOhneRechnungGiltDerHinweisDerBestellungUndEsGibtNurDenStorno(): void
    {
        $this->source->order['paymentInstructions'] = '<p>Sie zahlen beim Anbieter.</p>';

        $this->mail()->send(7, 15, null, 'https://shop.test/x');

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('Sie zahlen beim Anbieter.', $html);
        self::assertStringNotContainsString('Die neue Rechnung', $html);
        self::assertCount(1, $this->transport->sent[0]->getAttachments());
    }

    public function testEinAusgefallenerServerWirftNicht(): void
    {
        $this->transport->fail = true;

        self::assertFalse($this->mail()->send(7, 15, 16, 'https://shop.test/x'));
        self::assertNotEmpty($this->logger->errors);
    }

    private function mail(): PaymentChangeMail
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $translator = new ContaoLikeTranslator();
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

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

        $pdfs = new class implements DocumentPdfInterface {
            public function pdf(int $documentId): array
            {
                $name = 15 === $documentId ? 'ST-00002.pdf' : 'RE-00016.pdf';

                return ['pdf' => '%PDF-'.$documentId, 'fileName' => $name, 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
            }
        };

        return new PaymentChangeMail(
            $this->source,
            new MailRenderer($twig, $translator),
            new RecipientResolver(),
            $factory,
            new DocumentPayloadBuilder(),
            new InvoiceView(new MoneyFormatter(), new DocumentPayloadBuilder()),
            $pdfs,
            $this->logger,
        );
    }
}

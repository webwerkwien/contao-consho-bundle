<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityGuarantees;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmation;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrame;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrames;
use Webwerkwien\ContaoConshoBundle\Notification\MailImageFile;
use Webwerkwien\ContaoConshoBundle\Notification\RecipientResolver;
use Webwerkwien\ContaoConshoBundle\Notification\WarrantyNoticeSourceInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

class OrderConfirmationTest extends TestCase
{
    private FakeConfirmationSource $source;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    private array|null $notice = ['name' => 'gewaehrleistung-de.pdf', 'contents' => '%PDF-notice', 'contentType' => 'application/pdf'];

    private array|null $pdf = ['pdf' => '%PDF-invoice', 'fileName' => 'RE-00014.pdf', 'fromArchive' => true, 'archiveProblem' => false, 'logoMissing' => false];

    protected function setUp(): void
    {
        $this->source = new FakeConfirmationSource();
        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();
    }

    /** D-2: Without the withdrawal function (period 0) no "withdraw from contract" line. */
    public function testOhneWiderrufsfunktionKeinWiderrufslink(): void
    {
        $this->source->shopRow['withdrawalDays'] = 0;

        $this->confirmation()->send(7, 1, 'https://shop.test/bestellung?consho_order=abc', 1_758_800_000);

        self::assertStringNotContainsString('Vertrag widerrufen', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * F-3: The confirmation on a durable medium records consent and
     * acknowledgement (§ 7 Abs. 3 FAGG, § 312f Abs. 3 BGB) — translated in
     * both languages, and only where consent was given.
     */
    public function testDieZustimmungZumVorzeitigenBeginnStehtInDerBestaetigung(): void
    {
        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);
        self::assertStringNotContainsString('Widerrufsrecht verlieren', (string) $this->transport->sent[0]->getHtmlBody());

        $this->source->order['digitalConsentAt'] = 1_758_800_000;

        foreach (['de' => 'Ihr Widerrufsrecht verlieren', 'en' => 'lose your right of withdrawal'] as $language => $text) {
            $this->source->order['language'] = $language;
            $this->transport = new SpyTransport();

            $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

            self::assertStringContainsString($text, (string) $this->transport->sent[0]->getHtmlBody(), $language);
        }
    }

    /**
     * Abo-2, P14 (A12): For a service the confirmation records the request,
     * not the consent to the provision of content.
     */
    public function testBeiEinerDienstleistungStehtDerSatzZurDienstleistung(): void
    {
        $this->source->digital = true;
        $this->source->kind = 'service';
        $this->source->order['digitalConsentAt'] = 1_758_800_000;

        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);
        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('mit der Erbringung der Dienstleistung beginnen', $html);
        self::assertStringNotContainsString('Bereitstellung der digitalen Inhalte', $html);
    }

    /**
     * Abo-2 (P13): The terms of the subscription stand at its line in the
     * confirmation — the durable medium.
     */
    public function testDieAbobedingungenStehenInDerBestaetigung(): void
    {
        $this->source->terms = '1,00 € / Monat · Laufzeit 1 Jahr · endet danach';

        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

        self::assertStringContainsString('1,00 € / Monat · Laufzeit 1 Jahr · endet danach', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * F-7, F20: The confirmation goes out before any release — it says when
     * the digital content will be on the order page.
     */
    public function testDieBestaetigungSagtWannDieDigitalenInhalteKommen(): void
    {
        $this->source->digital = true;
        $periodEnds = 1_759_968_000; // 09.10.2025, 00:00 UTC — only the date counts

        foreach ([
            [true, null, 'stellen wir auf Ihrer Bestellseite bereit.'],
            [false, null, 'sobald Ihre Zahlung eingegangen ist.'],
            [true, $periodEnds, 'nach Ablauf der Widerrufsfrist am'],
            [false, $periodEnds, 'sobald Ihre Zahlung eingegangen und die Widerrufsfrist am'],
        ] as [$paid, $ends, $text]) {
            $releases = $this->createStub(DigitalRelease::class);
            $releases->method('paymentReady')->willReturn($paid);
            $releases->method('periodEndsAt')->willReturn($ends);
            $this->transport = new SpyTransport();

            $this->confirmation($releases)->send(7, 1, 'https://shop.test/o', 1);

            self::assertStringContainsString($text, (string) $this->transport->sent[0]->getHtmlBody());
        }

        // DL3: A service is activated, not provided.
        $this->source->kind = 'service';
        $this->transport = new SpyTransport();
        $this->confirmation($releases)->send(7, 1, 'https://shop.test/o', 1);
        $html = (string) $this->transport->sent[0]->getHtmlBody();
        self::assertStringContainsString('Die Leistungen Ihrer Bestellung schalten wir frei, sobald Ihre Zahlung eingegangen und die Widerrufsfrist am', $html);
        self::assertStringNotContainsString('digitalen Inhalte', $html);
        $this->source->kind = 'digital';

        // Without a digital item, no sentence about it.
        $this->source->digital = false;
        $this->transport = new SpyTransport();
        $this->confirmation($releases)->send(7, 1, 'https://shop.test/o', 1);
        self::assertStringNotContainsString('digitalen Inhalte', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * Block GARAN (GA4, GA6): The guarantee stands in the confirmation as it
     * is frozen on the item — as a sentence and with the label EMBEDDED
     * (cid:), not linked.
     */
    public function testDieHaltbarkeitsgarantieStehtMitEingebettetemLabelInDerBestaetigung(): void
    {
        $label = str_repeat("\x07", 16);
        $this->source->durability = ['durabilityYears' => 5, 'durabilityLabel' => $label];

        $images = $this->createMock(MailImageFile::class);
        $images->expects(self::once())->method('read')->with($label)->willReturn(['name' => 'garan.png', 'contents' => 'PNG-BYTES', 'contentType' => 'image/png']);

        $this->confirmation(images: $images)->send(7, 1, 'https://shop.test/o', 1);
        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();
        // With an extension: forwarded, the part appears as an image, not as a nameless attachment.
        $cid = 'garan-'.bin2hex($label).'.png';

        self::assertStringContainsString('Haltbarkeitsgarantie des Herstellers', $html);
        // Escaped like every text of the template: the straight quotation mark as &quot;.
        self::assertStringContainsString('Für „Wanderstock&quot; gewährt der Hersteller eine Haltbarkeitsgarantie von 5 Jahren.', $html);
        self::assertStringContainsString('src="cid:'.$cid.'"', $html);

        $inline = array_values(array_filter($mail->getAttachments(), static fn ($part): bool => $cid === $part->getName()));
        self::assertCount(1, $inline);
        self::assertSame('PNG-BYTES', $inline[0]->getBody());
        self::assertSame('inline', $inline[0]->getDisposition());
    }

    public function testZweiPositionenMitDemselbenLabelEinTeil(): void
    {
        $label = str_repeat("\x07", 16);
        $this->source->durability = ['durabilityYears' => 5, 'durabilityLabel' => $label];
        $this->source->extraItems = [['productName' => 'Wanderstock lang', 'sku' => 'WS-2', 'quantity' => '1.0000', 'unitPrice' => '0', 'taxRate' => '20.00', 'taxAmount' => '0', 'lineTotal' => '0', 'priceSource' => 'list', 'lineType' => 'product', 'kind' => 'physical', 'durabilityYears' => 5, 'durabilityLabel' => $label]];

        $images = $this->createMock(MailImageFile::class);
        $images->expects(self::once())->method('read')->willReturn(['name' => 'garan.jpg', 'contents' => 'JPG-BYTES', 'contentType' => 'image/jpeg']);

        $this->confirmation(images: $images)->send(7, 1, 'https://shop.test/o', 1);
        $mail = $this->transport->sent[0];

        self::assertSame(2, substr_count((string) $mail->getHtmlBody(), 'src="cid:garan-'.bin2hex($label).'.jpg"'));
        self::assertCount(1, array_filter($mail->getAttachments(), static fn ($part): bool => 'inline' === $part->getDisposition()));
    }

    /**
     * GARAN: two years on a frozen item are no guarantee within the meaning of
     * Z 12a; the width is the image's own, at most 300 px — never stretched.
     */
    public function testZweiJahreKeinLabelUndDieEigeneBreite(): void
    {
        $label = str_repeat("\x07", 16);
        $this->source->durability = ['durabilityYears' => 2, 'durabilityLabel' => $label];
        $quiet = $this->createMock(MailImageFile::class);
        $quiet->expects(self::never())->method('read');
        $this->confirmation(images: $quiet)->send(7, 1, 'https://shop.test/o', 1);
        self::assertStringNotContainsString('Haltbarkeitsgarantie', (string) $this->transport->sent[0]->getHtmlBody());

        // A real PNG of 1 × 1 pixel.
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->transport = new SpyTransport();
        $this->source->durability = ['durabilityYears' => 3, 'durabilityLabel' => $label];
        $images = $this->createMock(MailImageFile::class);
        $images->method('read')->willReturn(['name' => 'garan.png', 'contents' => $png, 'contentType' => 'image/png']);
        $this->confirmation(images: $images)->send(7, 1, 'https://shop.test/o', 1);
        self::assertStringContainsString('width="1" style="display: block; width: 1px;', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * GA9 and GA10: 2.5 years with the language's decimal comma, and the link
     * to where the QR code leads — readable even when the mail program blocks
     * images.
     */
    public function testHalbeJahreUndDerLinkAufIhrEuropa(): void
    {
        $this->source->durability = ['durabilityYears' => '2.5', 'durabilityLabel' => str_repeat("\x07", 16)];
        $images = $this->createMock(MailImageFile::class);
        $images->method('read')->willReturn(['name' => 'garan.png', 'contents' => 'PNG-BYTES', 'contentType' => 'image/png']);

        $this->confirmation(images: $images)->send(7, 1, 'https://shop.test/o', 1);
        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('Haltbarkeitsgarantie von 2,5 Jahren', $html);
        self::assertStringContainsString('Haltbarkeitsgarantie des Herstellers: 2,5 Jahre', $html);
        self::assertStringContainsString('<a href="'.DurabilityGuarantees::INFO_URL.'">Mehr zur Haltbarkeitsgarantie (Ihr Europa)</a>', $html);
    }

    /**
     * GARAN part 2 (L1–L4): at the top the logo, embedded; at the bottom
     * company, address and the operator's footer — in the text part too. The
     * copy to the operator stays without (L3).
     */
    public function testKopfUndFussDerKundenmail(): void
    {
        $frames = $this->createMock(MailFrames::class);
        $frames->expects(self::once())->method('of')->with(self::anything(), 'de')->willReturn(new MailFrame(
            'Müller & Söhne GmbH',
            'Musterstraße 1, 1100 Wien, Österreich',
            "FN 123456a, HG Wien\nIBAN AT00 0000",
            ['cid' => 'logo-ab.png', 'contents' => 'PNG-BYTES', 'contentType' => 'image/png', 'width' => 180],
        ));

        $this->confirmation(frames: $frames)->send(7, 1, 'https://shop.test/o', 1);
        $customer = $this->transport->sent[0];
        $html = (string) $customer->getHtmlBody();

        self::assertStringContainsString('<img src="cid:logo-ab.png" alt="Müller &amp; Söhne GmbH" width="180"', $html);
        self::assertStringContainsString('<p style="margin: 0;">Musterstraße 1, 1100 Wien, Österreich</p>', $html);
        self::assertStringContainsString('FN 123456a, HG Wien<br />', $html);
        self::assertLessThan(strpos($html, 'Musterstraße'), strpos($html, 'cid:logo-ab.png'), 'Logo oben, Absender unten');
        self::assertStringContainsString('Müller & Söhne GmbH', (string) $customer->getTextBody());

        $inline = array_values(array_filter($customer->getAttachments(), static fn ($part): bool => 'logo-ab.png' === $part->getName()));
        self::assertCount(1, $inline);
        self::assertSame('inline', $inline[0]->getDisposition());

        foreach (\array_slice($this->transport->sent, 1) as $copy) {
            self::assertStringNotContainsString('Musterstraße', (string) $copy->getHtmlBody(), 'die Betreiberkopie ohne Rahmen');
        }
    }

    public function testOhneDateiOderOhneGarantieKeinAbschnitt(): void
    {
        $images = $this->createMock(MailImageFile::class);
        $images->method('read')->willReturn(null);

        // File deleted: rather nothing than a broken image.
        $this->source->durability = ['durabilityYears' => 5, 'durabilityLabel' => str_repeat("\x07", 16)];
        $this->confirmation(images: $images)->send(7, 1, 'https://shop.test/o', 1);
        self::assertStringNotContainsString('Haltbarkeitsgarantie', (string) $this->transport->sent[0]->getHtmlBody());

        // No guarantee: not even asked.
        $this->transport = new SpyTransport();
        $this->source->durability = [];
        $quiet = $this->createMock(MailImageFile::class);
        $quiet->expects(self::never())->method('read');
        $this->confirmation(images: $quiet)->send(7, 1, 'https://shop.test/o', 1);
        self::assertStringNotContainsString('Haltbarkeitsgarantie', (string) $this->transport->sent[0]->getHtmlBody());
    }

    public function testDieKundenmailTraegtBestellungZahlungRechtstexteUndBeideAnhaenge(): void
    {
        self::assertTrue($this->confirmation()->send(7, 1, 'https://shop.test/bestellung?consho_order=abc', 1_758_800_000));

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('kundin@example.org', $mail->getTo()[0]->getAddress());
        self::assertSame('shop@web.example.net', $mail->getFrom()[0]->getAddress());
        self::assertSame('Ihre Bestellung WEB-00054', $mail->getSubject());
        self::assertStringContainsString('Guten Tag Erika Muster,', $html);
        self::assertStringContainsString('Wanderstock', $html);
        self::assertStringContainsString('13,90', $html);
        self::assertStringContainsString('AT61 1904 3002 3457 3201', $html);
        // The remittance information is the invoice number, as with the giro code.
        self::assertMatchesRegularExpression('#Verwendungszweck</th><td[^>]*>RE-00014</td>#', $html);
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', $html);
        self::assertStringContainsString('<p>Das sind die AGB.</p>', $html);
        self::assertStringContainsString('<p>Widerrufsbelehrung und Muster-Formular.</p>', $html);
        // C24, since D-2: the withdrawal link leads to the declaration on the order page.
        self::assertStringContainsString('<a href="https://shop.test/bestellung?consho_order=abc&amp;consho_withdraw=1">Vertrag widerrufen</a>', $html);
        // Whoever reads only text needs the address — not just "View your order".
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', (string) $mail->getTextBody());

        $names = array_map(static fn ($part): string => (string) $part->getFilename(), $mail->getAttachments());
        self::assertSame(['RE-00014.pdf', 'gewaehrleistung-de.pdf'], $names);

        self::assertSame([7 => 1_758_800_000], $this->source->sent);
    }

    /**
     * Contao fills contao_default with vsprintf. A key that is missing appears
     * as a key — none may stand in either of the two languages.
     */
    public function testKeinUnuebersetzterSchluesselInKundenmailUndBetreiberkopie(): void
    {
        foreach (['de', 'en'] as $language) {
            $this->source->order['language'] = $language;
            $this->source->shopRow['fallbackLanguage'] = $language;
            $this->transport = new SpyTransport();

            $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

            self::assertCount(2, $this->transport->sent);

            foreach ($this->transport->sent as $mail) {
                self::assertStringNotContainsString('CONSHO.', (string) $mail->getHtmlBody().$mail->getSubject(), $language);
            }
        }
    }

    public function testDieBetreiberkopieGehtInDerShopspracheAnDieBetreiberadresse(): void
    {
        $this->source->shopRow['fallbackLanguage'] = 'en';

        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

        $copy = $this->transport->sent[1];
        $html = (string) $copy->getHtmlBody();

        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertSame('New order WEB-00054 for €13.90', $copy->getSubject());
        self::assertStringContainsString('kundin@example.org', $html);
        self::assertStringNotContainsString('Das sind die AGB.', $html);
        self::assertSame('RE-00014.pdf', $copy->getAttachments()[0]->getFilename());
    }

    /**
     * The payment block comes from the invoice snapshot. Sending again in a
     * year must not name an account the shop has in the meantime.
     */
    public function testDieBankverbindungKommtAusDemRechnungsSnapshot(): void
    {
        $this->source->invoiceRow['payload']['payment']['details']['iban'] = 'AT02 2011 1000 0000 0000';

        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

        self::assertStringContainsString('AT02 2011 1000 0000 0000', (string) $this->transport->sent[0]->getHtmlBody());
    }

    public function testOhneRechnungKeinPdfUndKeinVerwendungszweck(): void
    {
        $this->source->invoiceRow = null;
        $this->pdf = null;

        self::assertTrue($this->confirmation()->send(7, 1, 'https://shop.test/o', 1));

        $mail = $this->transport->sent[0];
        self::assertStringNotContainsString('Verwendungszweck', (string) $mail->getHtmlBody());
        self::assertSame(['gewaehrleistung-de.pdf'], array_map(static fn ($part): string => (string) $part->getFilename(), $mail->getAttachments()));
    }

    /**
     * Without an invoice at purchase, the payment notice comes from the order
     * itself.
     */
    public function testOhneRechnungKommtDerZahlungshinweisVonDerBestellung(): void
    {
        $this->source->invoiceRow = null;
        $this->pdf = null;
        $this->source->order['paymentInstructions'] = '<p>Sie werden zur Zahlung weitergeleitet.</p>';

        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

        self::assertStringContainsString('<p>Sie werden zur Zahlung weitergeleitet.</p>', (string) $this->transport->sent[0]->getHtmlBody());
    }

    public function testOhneGewaehrleistungsDateiGehtDieMailTrotzdem(): void
    {
        $this->notice = null;

        self::assertTrue($this->confirmation()->send(7, 1, 'https://shop.test/o', 1));
        self::assertStringNotContainsString('Gewährleistungsrecht finden Sie im Anhang', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * The order already exists. A mail server that does not answer is logged
     * and leaves `confirmationSentAt` empty — nothing is thrown.
     */
    public function testScheitertDerVersandBleibtEsBeiFalseUndEinemEintragImLog(): void
    {
        $this->transport->fail = true;

        self::assertFalse($this->confirmation()->send(7, 1, 'https://shop.test/o', 1));
        self::assertSame([], $this->source->sent);
        self::assertStringContainsString('order confirmation of order WEB-00054', $this->logger->errors[0]);
    }

    public function testOhneMailKonfigurationKeinVersandAberEinEintragImLog(): void
    {
        $this->source->shopRow['transport'] = null;

        self::assertFalse($this->confirmation()->send(7, 1, 'https://shop.test/o', 1));
        self::assertSame([], $this->transport->sent);
        self::assertStringContainsString('no mail configuration', $this->logger->errors[0]);
    }

    /**
     * Only the customer mail counts as the confirmation (§ 7 Abs 3 FAGG).
     */
    public function testEineGescheiterteBetreiberkopieMachtDieBestellungNichtUnbestaetigt(): void
    {
        $this->transport->failFor = 'office@web.example.net';

        self::assertTrue($this->confirmation()->send(7, 1, 'https://shop.test/o', 5));
        self::assertSame([7 => 5], $this->source->sent);
        self::assertStringContainsString('operator copy of order WEB-00054', $this->logger->errors[0]);
    }

    /**
     * What is on the invoice belongs in the confirmation too — the reverse
     * charge notice and the delivery period, both from the invoice snapshot.
     */
    public function testHinweisUndLieferzeitraumKommenAusDemRechnungsSnapshot(): void
    {
        $this->source->itemSource = 'reverseCharge';
        $this->source->invoiceRow['payload']['notes'] = ['footer' => '', 'reverseCharge' => 'Übergang der Steuerschuld auf den Leistungsempfänger'];
        $this->source->invoiceRow['payload']['delivery'] = ['from' => '2026-09-28', 'to' => '2026-10-01'];

        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('Übergang der Steuerschuld auf den Leistungsempfänger', $html);
        self::assertStringContainsString('Voraussichtl. Lieferung', $html);
        self::assertStringContainsString('28.09.2026', $html);
    }

    /**
     * The mail is out, only the timestamp is not. That is a success — and the
     * message must not tempt anyone to "send again", otherwise the customer
     * has the confirmation twice.
     */
    public function testGescheitertesVermerkenIstKeinGescheiterterVersand(): void
    {
        $this->source->failMarking = true;

        self::assertTrue($this->confirmation()->send(7, 1, 'https://shop.test/o', 1));
        self::assertCount(2, $this->transport->sent);
        self::assertStringContainsString('was sent, but the time could not be recorded', $this->logger->errors[0]);
    }

    /**
     * The same server gives the same answer. Still trying the copy would cost
     * the buyer a second timeout.
     */
    public function testNachEinemTransportfehlerKeinZweiterVersuchFuerDieKopie(): void
    {
        $this->transport->fail = true;

        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1);

        self::assertSame(1, $this->transport->attempts);
        self::assertCount(1, $this->logger->errors);
    }

    public function testErneutSendenOhneBetreiberkopie(): void
    {
        $this->confirmation()->send(7, 1, 'https://shop.test/o', 1, false);

        self::assertCount(1, $this->transport->sent);
        self::assertSame('kundin@example.org', $this->transport->sent[0]->getTo()[0]->getAddress());
    }

    private function confirmation(DigitalRelease|null $releases = null, MailImageFile|null $images = null, MailFrames|null $frames = null): OrderConfirmation
    {
        $loader = new FilesystemLoader();
        // The ROOT of contao/templates: Contao keeps the folder in the name.
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

        $pdf = $this->pdf;
        $pdfs = new class($pdf) implements DocumentPdfInterface {
            public function __construct(private readonly array|null $pdf)
            {
            }

            public function pdf(int $documentId): array|null
            {
                return $this->pdf;
            }
        };

        $notice = $this->notice;
        $warranty = new class($notice) implements WarrantyNoticeSourceInterface {
            public function __construct(private readonly array|null $notice)
            {
            }

            public function forRoot(int $rootId): array|null
            {
                return $this->notice;
            }
        };

        return new OrderConfirmation(
            $this->source,
            new MailRenderer($twig, $translator),
            new RecipientResolver(),
            $factory,
            new DocumentPayloadBuilder(),
            new InvoiceView(new MoneyFormatter(), new DocumentPayloadBuilder()),
            $pdfs,
            $warranty,
            $this->logger,
            $releases,
            $images,
            $frames,
        );
    }
}

/**
 * Contao's translator for contao_default, reduced to what matters here:
 * TL_LANG per locale, parameters filled with vsprintf (Translation/Translator.php).
 */
final class ContaoLikeTranslator implements TranslatorInterface, LocaleAwareInterface
{
    /** @var array<string, array<string, mixed>> */
    private static array $languages = [];

    private string $locale = 'de';

    public function __construct()
    {
        foreach (['de', 'en'] as $language) {
            if (!isset(self::$languages[$language])) {
                $saved = $GLOBALS['TL_LANG'] ?? null;
                $GLOBALS['TL_LANG'] = [];
                require __DIR__.'/../../contao/languages/'.$language.'/default.php';
                // From Contao's core (default.xlf), which the GARAN years use (GA9).
                $GLOBALS['TL_LANG']['MSC']['decimalSeparator'] = 'de' === $language ? ',' : '.';
                self::$languages[$language] = $GLOBALS['TL_LANG'];
                $GLOBALS['TL_LANG'] = $saved;
            }
        }
    }

    public function trans(string $id, array $parameters = [], string|null $domain = null, string|null $locale = null): string
    {
        $value = self::$languages[$locale ?? $this->locale] ?? [];

        foreach (explode('.', $id) as $key) {
            if (!\is_array($value) || !isset($value[$key])) {
                return $id;
            }

            $value = $value[$key];
        }

        if (!\is_string($value)) {
            return $id;
        }

        return [] === $parameters ? $value : vsprintf($value, array_values($parameters));
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}

final class ErrorSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $errors = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->errors[] = (string) $message;
    }
}

final class SpyTransport extends AbstractTransport
{
    /** @var list<Email> */
    public array $sent = [];

    public bool $fail = false;

    public int $attempts = 0;

    /**
     * The mails so far, read anew: whatever sends sits on another object, and
     * PHPStan keeps a property it read before such a call.
     *
     * @return list<Email>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public string $failFor = '';

    public function __toString(): string
    {
        return 'spy://';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        \assert($email instanceof Email);
        ++$this->attempts;

        if ($this->fail || ('' !== $this->failFor && $this->failFor === $email->getTo()[0]->getAddress())) {
            throw new TransportException('Connection could not be established with host "smtp.example.org:587"');
        }

        $this->sent[] = $email;
    }
}

final class FakeConfirmationSource implements OrderConfirmationSourceInterface
{
    /** @var array<string, mixed> */
    public array $order = [
        'id' => 7, 'shop' => 1, 'orderNumber' => 'WEB-00054', 'currency' => 'EUR', 'language' => 'de',
        'salutation' => '', 'firstname' => 'Erika', 'lastname' => 'Muster', 'company' => '',
        'email' => 'kundin@example.org', 'createdAt' => 1_758_700_000,
        'netTotal' => '11.58', 'taxTotal' => '2.32', 'grandTotal' => '13.90',
        'shippingMethodTitle' => 'Paket', 'paymentMethodTitle' => 'Vorkasse',
        'billingStreet' => 'Musterstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingCountry' => 'AT',
        'confirmationSentAt' => null,
    ];

    /** @var array{operatorName: string, operatorAddress: string, fallbackLanguage: string, withdrawalDays: int, transport: array<string, mixed>|null} */
    public array $shopRow = [
        'operatorName' => 'Mein Shop',
        'operatorAddress' => 'office@web.example.net',
        'fallbackLanguage' => 'de',
        'withdrawalDays' => 14,
        'transport' => ['type' => 'smtp', 'senderAddress' => 'shop@web.example.net', 'senderName' => 'Mein Shop'],
    ];

    /** @var array{id: int, documentNumber: string, payload: array<string, mixed>}|null */
    public array|null $invoiceRow = [
        'id' => 14,
        'documentNumber' => 'RE-00014',
        'payload' => ['payment' => [
            'instructions' => '<p>Bitte überweisen Sie den Betrag.</p>',
            'details' => ['accountHolder' => 'Muster GmbH', 'iban' => 'AT61 1904 3002 3457 3201', 'bic' => '', 'bankName' => ''],
        ]],
    ];

    /** @var array<int, int> */
    public array $sent = [];

    public bool $failMarking = false;

    public string $itemSource = 'list';

    /** F-7: the hiking pole as a digital item. */
    public bool $digital = false;

    /** DL1: frozen kind of a digital item (digital or service), Abo-2: its subscription terms. */
    public string $kind = 'digital';

    public string $terms = '';

    /** Block GARAN: frozen durability guarantee on the hiking pole. */
    public array $durability = [];

    /** Further items at the end (Block GARAN: two lines, one label). */
    public array $extraItems = [];

    public function order(int $orderId): array
    {
        return $this->order;
    }

    public function items(int $orderId): array
    {
        return [
            ['productName' => 'Wanderstock', 'sku' => 'WS-1', 'quantity' => '1.0000', 'unitPrice' => '7.4167', 'taxRate' => '20.00', 'taxAmount' => '1.4833', 'lineTotal' => '8.9000', 'priceSource' => $this->itemSource, 'lineType' => 'product', 'kind' => $this->digital ? $this->kind : 'physical', 'subscriptionTerms' => $this->terms, ...$this->durability],
            ['productName' => 'Paket', 'sku' => '', 'quantity' => '1.0000', 'unitPrice' => '4.1667', 'taxRate' => '20.00', 'taxAmount' => '0.8333', 'lineTotal' => '5.0000', 'priceSource' => 'list', 'lineType' => 'shipping'],
            ...$this->extraItems,
        ];
    }

    public function legalTexts(int $orderId): array
    {
        return [
            ['headline' => 'AGB', 'text' => '<p>Das sind die AGB.</p>'],
            ['headline' => 'Widerrufsbelehrung', 'text' => '<p>Widerrufsbelehrung und Muster-Formular.</p>'],
        ];
    }

    public function invoice(int $orderId): array|null
    {
        return $this->invoiceRow;
    }

    /** @var array<int, array{id: int, documentNumber: string, payload: array<string, mixed>}> */
    public array $documents = [];

    public function document(int $orderId, int $documentId): array|null
    {
        return $this->documents[$documentId] ?? null;
    }

    public function shop(int $shopId): array
    {
        return $this->shopRow;
    }

    public function markSent(int $orderId, int $now): void
    {
        if ($this->failMarking) {
            throw new \RuntimeException('Lost connection to MySQL server');
        }

        $this->sent[$orderId] = $now;
    }

    public function rootOf(int $shopId, string $language): int
    {
        return 1;
    }

    /** @var list<array{quantity: string, name: string, service: bool}> */
    public array $entitlementRows = [];

    public function entitlements(int $orderId, int $since = 0): array
    {
        return $this->entitlementRows;
    }
}

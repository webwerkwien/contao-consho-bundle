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
use Webwerkwien\ContaoConshoBundle\Notification\MailFrame;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrames;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\RecipientResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

// The fakes of the confirmation — the same source, transport and translator.
require_once __DIR__.'/OrderConfirmationTest.php';

/**
 * Die Mails nach Zahlung und Versand (D-1, Z3 und Z4), mit den echten
 * Vorlagen und den echten Sprachdateien. Bis D-1 benutzten beide Vorlagen
 * Schlüssel, die es nicht gab — gerendert hätten sie „consho.mail.…".
 */
final class OrderEventMailTest extends TestCase
{
    private FakeConfirmationSource $source;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    protected function setUp(): void
    {
        $this->source = new FakeConfirmationSource();
        $this->source->documents = [
            16 => ['id' => 16, 'documentNumber' => 'RE-00016', 'payload' => []],
        ];
        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();
    }

    public function testZahlungErhaltenMitDerGeradeAusgestelltenRechnung(): void
    {
        self::assertTrue($this->mail()->paymentReceived(7, 16, 'https://shop.test/bestellung?consho_order=abc', false));

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertCount(1, $this->transport->sent, 'im Backend erfasst: keine Kopie');
        self::assertSame('Zahlung erhalten: Bestellung WEB-00054', $mail->getSubject());
        self::assertStringContainsString('wir haben Ihre Zahlung für die Bestellung WEB-00054 erhalten.', $html);
        self::assertStringContainsString('Die Rechnung RE-00016 finden Sie im Anhang.', $html);
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertSame(['RE-00016.pdf'], array_map(static fn ($p): string => (string) $p->getFilename(), $mail->getAttachments()));
    }

    /**
     * D-7, M7: Die dreizeilige Zahlungsmail landete bei Gmail im Spam — jetzt
     * mit dem Block, den auch die Versandmail trägt: Datum, Anschrift,
     * Versandart und die Artikel, ohne Versand- und Zahlungszeilen.
     */
    /**
     * GARAN Teil 2 (Review 3): auch die Ereignismails tragen Kopf und Fuß —
     * mit dem Shop der Bestellung und der Sprache des Kunden; die Kopie an
     * den Betreiber nicht.
     */
    public function testKopfUndFussAuchInDenEreignismails(): void
    {
        $frames = $this->createMock(MailFrames::class);
        $frames->expects(self::once())->method('of')->with((int) $this->source->order(7)['shop'], 'de')->willReturn(new MailFrame('Muster GmbH', 'Musterstraße 1, 1100 Wien', 'FN 1a', ['cid' => 'logo-ab.png', 'contents' => 'PNG-BYTES', 'contentType' => 'image/png', 'width' => 120]));

        $this->mail($frames)->paymentReceived(7, 16, 'https://shop.test/x', true);
        $customer = $this->transport->sent[0];

        self::assertStringContainsString('src="cid:logo-ab.png"', (string) $customer->getHtmlBody());
        self::assertStringContainsString('Musterstraße 1, 1100 Wien', (string) $customer->getHtmlBody());
        self::assertContains('logo-ab.png', array_map(static fn ($part): string => (string) $part->getName(), $customer->getAttachments()));

        self::assertCount(2, $this->transport->sent, 'mit Kopie an den Betreiber');
        self::assertStringNotContainsString('Musterstraße', (string) $this->transport->sent[1]->getHtmlBody());
    }

    public function testZahlungErhaltenNenntDieBestellung(): void
    {
        $this->mail()->paymentReceived(7, null, 'https://shop.test/x', false);

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('Bestelldatum', $html);
        self::assertStringContainsString('Lieferadresse', $html);
        self::assertStringContainsString('<li>1 × Wanderstock</li>', $html);
        self::assertStringNotContainsString('× Paket', $html, 'die Versandzeile ist kein Artikel');
    }

    /**
     * F-7, F20: Hat die Zahlung digitale Inhalte freigegeben, sagt es die
     * Zahlungsmail; die eigene Mail „bereit" kommt nur nach einer Freigabe,
     * die keine andere Mail ankündigt — ohne Schlüssel darin.
     */
    public function testDigitaleInhalteInDerZahlungsmailUndAlsEigeneMail(): void
    {
        $this->mail()->paymentReceived(7, null, 'https://shop.test/x', false);
        self::assertStringNotContainsString('digitalen Inhalte', (string) $this->transport->sent[0]->getHtmlBody());

        $this->source->entitlementRows = [['quantity' => '2.0000', 'name' => 'Spiel', 'service' => false]];
        $this->transport = new SpyTransport();
        $this->mail()->paymentReceived(7, null, 'https://shop.test/x', false);
        self::assertStringContainsString('Ihre digitalen Inhalte stehen jetzt auf Ihrer Bestellseite bereit.', (string) $this->transport->sent[0]->getHtmlBody());

        $this->transport = new SpyTransport();
        self::assertTrue($this->mail()->digitalReleased(7, 100, 'https://shop.test/bestellung?consho_order=abc'));
        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Ihre digitalen Inhalte: Bestellung WEB-00054', $mail->getSubject());
        self::assertStringContainsString('<li>2 × Spiel</li>', $html);
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertSame([], $mail->getAttachments());
    }

    /**
     * WG4: „Ihr Gutschein" — mit dem Code im Klartext (anders als ein
     * Lizenzschlüssel, F20: ein Geschenk wird weitergegeben) und je Gutschein
     * einem PDF im Anhang.
     */
    public function testDieGutscheinmailBringtCodeUndPdf(): void
    {
        $this->voucherConnection = \Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_shop'] as $table) {
            \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->voucherConnection, $table);
        }

        $this->voucherConnection->insert('tl_consho_shop', ['id' => 3, 'title' => 'Testshop']);
        $id = (new \Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger($this->voucherConnection))->issue(3, 'EUR', '50', '50', null, 'purchase', 1_800_000_000, 7, 10);
        $code = \Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode::format((string) $this->voucherConnection->fetchOne('SELECT code FROM tl_consho_voucher'));

        self::assertTrue($this->mail()->vouchersIssued(7, [$id], 'https://shop.test/bestellung?consho_order=abc'));
        $mail = $this->transport->sent[0];
        $html = str_replace("\u{a0}", ' ', (string) $mail->getHtmlBody());

        self::assertSame('Ihr Gutschein: Bestellung WEB-00054', $mail->getSubject());
        self::assertStringContainsString($code, $html);
        self::assertStringContainsString('50,00 €', $html);
        self::assertStringContainsString('Unbefristet gültig', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertCount(1, $mail->getAttachments());
        self::assertMatchesRegularExpression('/^Gutschein-[A-Z2-9]{4}\.pdf$/', (string) $mail->getAttachments()[0]->getFilename());
    }

    /**
     * DL3: Eine Dienstleistung ist freigeschaltet, nicht „bereit"; gemischt
     * nennt beides.
     */
    public function testEineDienstleistungIstFreigeschaltet(): void
    {
        $this->source->entitlementRows = [['quantity' => '1.0000', 'name' => 'Mitgliedschaft', 'service' => true]];
        $this->mail()->paymentReceived(7, null, 'https://shop.test/x', false);
        self::assertStringContainsString('Ihre Leistungen sind jetzt freigeschaltet', (string) $this->transport->sent[0]->getHtmlBody());

        $this->transport = new SpyTransport();
        $this->mail()->digitalReleased(7, 100, 'https://shop.test/x');
        self::assertSame('Freigeschaltet: Bestellung WEB-00054', $this->transport->sent[0]->getSubject());

        $this->source->entitlementRows[] = ['quantity' => '1.0000', 'name' => 'E-Book', 'service' => false];
        $this->transport = new SpyTransport();
        $this->mail()->digitalReleased(7, 100, 'https://shop.test/x');
        self::assertSame('Bereit und freigeschaltet: Bestellung WEB-00054', $this->transport->sent[0]->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * Abnahme DL, Fund 4: Die Rechnung, die eine Freigabe ausgestellt hat,
     * liegt der Freigabemail bei — und die Mail sagt es.
     */
    public function testDieFreigabemailTraegtIhreRechnung(): void
    {
        $this->source->entitlementRows = [['quantity' => '1.0000', 'name' => 'Beratungspaket', 'service' => true]];

        self::assertTrue($this->mail()->digitalReleased(7, 100, 'https://shop.test/x', 16));
        $mail = $this->transport->sent[0];

        self::assertStringContainsString('Die Rechnung RE-00016 liegt dieser E-Mail bei.', (string) $mail->getHtmlBody());
        self::assertSame(['RE-00016.pdf'], array_map(static fn ($p): string => (string) $p->getFilename(), $mail->getAttachments()));
    }

    public function testOnlineBezahltBekommtDerBetreiberEineKopie(): void
    {
        $this->mail()->paymentReceived(7, null, 'https://shop.test/x', true);

        self::assertCount(2, $this->transport->sent);

        $copy = $this->transport->sent[1];

        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertSame('Zahlung eingegangen: Bestellung WEB-00054', $copy->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('Anhang', (string) $this->transport->sent[0]->getHtmlBody(), 'keine neue Rechnung, kein Hinweis');
    }

    public function testVersendetNenntWasImPaketIstUndDassNochEtwasKommt(): void
    {
        self::assertTrue($this->mail()->shipped(7, [
            'carrier' => 'Post AT',
            'trackingNumber' => 'CA123456789AT',
            'items' => [['quantity' => '2', 'name' => 'Wanderstock']],
            'complete' => false,
        ], null, 'https://shop.test/x'));

        self::assertCount(1, $this->transport->sent, 'keine Kopie: der Betreiber hat gemeldet');

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Ihre Bestellung WEB-00054 ist unterwegs', $mail->getSubject());
        self::assertStringContainsString('2 × Wanderstock', $html);
        self::assertStringContainsString('Sendungsnummer (Post AT): CA123456789AT', $html);
        self::assertStringContainsString('Die übrigen Artikel folgen', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertSame([], $mail->getAttachments());
    }

    /**
     * Nach der Abnahme: Die nackte Versandmail landete als einzige im Junk.
     * Datum, Anschrift und Versandart unterscheiden sie von Paket-Phishing —
     * und die Anschrift ist Kundeneingabe, also maskiert.
     */
    public function testVersendetTraegtDatumUndAnschriftMaskiert(): void
    {
        $this->source->order['billingStreet'] = 'Hauptstraße 1 <b>x</b>';

        $this->mail()->shipped(7, ['carrier' => '', 'trackingNumber' => '', 'items' => [], 'complete' => true], null, 'https://shop.test/x');

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('Bestelldatum', $html);
        self::assertStringContainsString('Lieferadresse', $html);
        self::assertStringContainsString('Hauptstraße 1 &lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testDieLetzteSendungOhneSendungsnummerMitRechnung(): void
    {
        $this->mail()->shipped(7, ['carrier' => '', 'trackingNumber' => '', 'items' => [['quantity' => '1', 'name' => 'Rucksack']], 'complete' => true], 16, 'https://shop.test/x');

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringNotContainsString('Sendungsnummer', $html);
        self::assertStringNotContainsString('Die übrigen Artikel', $html);
        self::assertStringContainsString('Die Rechnung RE-00016 finden Sie im Anhang.', $html);
        self::assertCount(1, $this->transport->sent[0]->getAttachments());
    }

    /**
     * D-2: Eingangsbestätigung mit dem Inhalt der Erklärung und Datum UND
     * Uhrzeit des Eingangs (§ 356a Abs. 3 BGB, § 13a FAGG). Die Kopie geht
     * immer an den Betreiber.
     */
    public function testWiderrufsbestaetigungMitDatumUhrzeitUndErklaerung(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            $received = (new \DateTimeImmutable('2026-10-02 14:03:05', new \DateTimeZone('Europe/Vienna')))->getTimestamp();

            self::assertTrue($this->mail()->withdrawalReceived(7, $this->withdrawal($received, [
                'scope' => 'items',
                'items' => [['quantity' => '2.0000', 'name' => 'Wanderstock <b>']],
                'message' => "Passt nicht.\n<script>",
            ]), 'https://shop.test/x'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(2, $this->transport->sent);

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Eingangsbestätigung: Widerruf zur Bestellung WEB-00054', $mail->getSubject());
        self::assertStringContainsString('2. Oktober 2026', $html);
        self::assertStringContainsString('14:03:05', $html, 'die Uhrzeit, nicht nur das Datum');
        self::assertStringContainsString('WEB-00054-W1', $html);
        self::assertStringContainsString('2 × Wanderstock &lt;b&gt;', $html, 'Menge ohne Nullen, Name maskiert');
        self::assertStringContainsString('Passt nicht.<br />', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('CONSHO.', $html);

        // D-7, M7: dazu die Bestellung, zu der die Erklärung gehört — ohne
        // diesen Block landete die Bestätigung bei Gmail im Spam.
        self::assertStringContainsString('Die Bestellung:', $html);
        self::assertStringContainsString('<li>1 × Wanderstock</li>', $html);
        self::assertStringContainsString('Lieferadresse', $html);

        $copy = $this->transport->sent[1];

        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertSame('Widerruf eingegangen: Bestellung WEB-00054', $copy->getSubject());
        self::assertStringContainsString('über die Bestellseite', (string) $copy->getHtmlBody());
        // Abnahme D-2: Die Kopie sagte dem Betreiber „Ihre Nachricht".
        self::assertStringContainsString('Nachricht des Kunden', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('Ihre Nachricht', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
    }

    /**
     * WA, A3: Ein Widerruf, der zu keiner Bestellung passt, wird an die
     * EINGEGEBENE Adresse bestätigt — mit Erklärung, Datum und Uhrzeit, aber
     * ohne ein Wort über eine Bestellung: die Adresse kann jeder eingetippt
     * haben.
     */
    public function testNichtZugeordneterWiderrufOhneBestelldaten(): void
    {
        self::assertTrue($this->mail()->withdrawalReceivedUnassigned(1, 'fremd@example.net', 'de', [
            'number' => 'W-00042',
            'receivedAt' => 1_790_000_000,
            'name' => 'Erika <b>Muster</b>',
            'orderReference' => 'WEB-0005',
            'email' => 'fremd@example.net',
            'scope' => 'all',
            'part' => '',
            'message' => "Bitte rasch.\n<script>",
        ]));

        self::assertCount(2, $this->transport->sent, 'mit Kopie an den Betreiber');

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('fremd@example.net', $mail->getTo()[0]->getAddress(), 'die eingegebene Adresse, nicht die einer Bestellung');
        self::assertSame('Eingangsbestätigung: Widerruf W-00042', $mail->getSubject());
        self::assertStringContainsString('WEB-0005', $html, 'die eingetippte Bestellnummer');
        self::assertStringContainsString('Erika &lt;b&gt;Muster&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        // Review WA, S3: jede Stelle mit dem Namen, nicht nur die Anrede.
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('WEB-00054', $html, 'nichts aus einer Bestellung');
        self::assertStringNotContainsString('Lieferadresse', $html);
        self::assertStringNotContainsString('kundin@example.org', $html);
        self::assertStringNotContainsString('CONSHO.', $html);

        $copy = $this->transport->sent[1];

        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertSame('Widerruf eingegangen, nicht zugeordnet: W-00042', $copy->getSubject());
        self::assertStringContainsString('Erika &lt;b&gt;Muster&lt;/b&gt;', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('<b>', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('<script>', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('nicht hinausgegangen', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
    }

    public function testWiderrufOhneBestellseiteGehtTrotzdemHinaus(): void
    {
        self::assertTrue($this->mail()->withdrawalReceived(7, $this->withdrawal(1_790_000_000, ['scope' => 'part', 'part' => 'nur die Schuhe']), ''));

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('nur die Schuhe', $html);
        self::assertStringNotContainsString('Bestellung ansehen', $html);
    }

    /** D-3, F6: die Begründung des Betreibers, wörtlich und maskiert. */
    public function testAblehnungMitDerBegruendungDesBetreibers(): void
    {
        self::assertTrue($this->mail()->withdrawalRejected(7, 'WEB-00054-W1', "Die Frist war abgelaufen.\n<i>Gruß</i>", 'https://shop.test/x'));

        self::assertCount(1, $this->transport->sent, 'keine Kopie: der Betreiber hat abgelehnt');

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Ihr Widerruf WEB-00054-W1 zur Bestellung WEB-00054', $mail->getSubject());
        self::assertStringContainsString('Die Frist war abgelaufen.<br />', $html);
        self::assertStringContainsString('&lt;i&gt;Gruß&lt;/i&gt;', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /** D-3, F4: Erstattung mit dem Beleg im Anhang. */
    public function testErstattungMitBelegImAnhang(): void
    {
        self::assertTrue($this->mail()->refundBooked(7, 'WEB-00054-W1', '30,00 €', '30,00 €', [16], 'https://shop.test/x'));

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Ihr Widerruf WEB-00054-W1 zur Bestellung WEB-00054: Erstattung', $mail->getSubject());
        self::assertStringContainsString('Wir erstatten Ihnen 30,00 € mit demselben Zahlungsmittel', $html);
        self::assertStringContainsString('Die Belege finden Sie im Anhang: RE-00016.', $html);
        self::assertCount(1, $mail->getAttachments());
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * Abo-8, P81: keine neue Mail — „Erstattung gebucht" nennt den
     * einbehaltenen Wertersatz und das beendete Abo.
     */
    public function testErstattungNenntWertersatzUndBeendetesAbo(): void
    {
        $this->mail()->refundBooked(7, 'WEB-00054-W1', '23,00 €', '23,00 €', [], '', '', '7,00 €', ['WEB-00054-1']);

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('behalten wir einen Wertersatz von 7,00 € ein', $html);
        self::assertStringContainsString('Ihr Abo WEB-00054-1 ist mit dem Widerruf beendet.', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * WG-6 (WG11): die Mail sagt, was davon auf den Gutschein zurückging.
     */
    public function testErstattungNenntDenTeilAufDemGutschein(): void
    {
        $this->mail()->refundBooked(7, 'WEB-00054-W1', '30,00 €', '30,00 €', [], '', '', '', [], '20,00 €');

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('Wir erstatten Ihnen 30,00 €', $html);
        self::assertStringContainsString('Davon schreiben wir 20,00 € Ihrem Gutschein gut', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    public function testOhneAuszahlungSinktDerBetrag(): void
    {
        $this->mail()->refundBooked(7, 'WEB-00054-W1', '', '30,00 €', [], '');

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('verringert sich um 30,00 €', $html);
        self::assertStringNotContainsString('Wir erstatten', $html);
        self::assertStringNotContainsString('Anhang', $html);
        self::assertStringNotContainsString('Bestellung ansehen', $html);
        self::assertStringNotContainsString('Wertersatz', $html);
        self::assertStringNotContainsString('Ihr Abo', $html);
        self::assertStringNotContainsString('Gutschein', $html);
    }

    /**
     * Abo-4, P30: die Rechnung einer Folgebestellung. Abonummer, Leistungs-
     * zeitraum und die Rechnung im Anhang — und keine Widerrufsbelehrung, kein
     * Zustimmungssatz: eine Folgebestellung öffnet kein neues Fenster (A12).
     * Die Kopie geht an den Betreiber.
     */
    public function testRechnungZumAboMitZeitraumUndOhneWiderrufsbelehrung(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->source->documents[14] = ['id' => 14, 'documentNumber' => 'RE-00014', 'payload' => []];

        try {
            $from = (new \DateTimeImmutable('2026-10-29 16:36'))->getTimestamp();
            $until = (new \DateTimeImmutable('2026-11-29 16:36'))->getTimestamp();

            self::assertTrue($this->mail()->subscriptionInvoice(7, 'WEB-00084-1', $from, $until, 'https://shop.test/bestellung?consho_order=abc'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(2, $this->transport->sent, 'Kunde und Betreiber');

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Ihre Rechnung zum Abo WEB-00084-1', $mail->getSubject());
        self::assertStringContainsString('WEB-00084-1', $html);
        self::assertStringContainsString('29. Oktober 2026', $html);
        self::assertStringContainsString('29. November 2026', $html);
        self::assertStringContainsString('RE-00014', $html);
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertSame(['RE-00016.pdf'], array_map(static fn ($p): string => (string) $p->getFilename(), $mail->getAttachments()), 'die Rechnung hängt an');

        // A12: Gegenprobe gegen die Bestätigungsmail, die beides trägt.
        self::assertStringNotContainsString('Widerruf', $html);
        self::assertStringNotContainsString('verlange', $html);

        $copy = $this->transport->sent[1];
        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertStringContainsString('WEB-00084-1', (string) $copy->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
    }

    /**
     * Abo-4b, P97: die zweite Fassung — abgebucht am Tag X über die Zahlungsart
     * der Bestellung; beim Betreiber ebenso, nicht „Auf Rechnung".
     */
    public function testRechnungZumAboAbgebucht(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->source->order['paymentMethodTitle'] = 'Kreditkarte';

        try {
            $charged = (new \DateTimeImmutable('2026-10-29 17:00'))->getTimestamp();
            self::assertTrue($this->mail()->subscriptionInvoice(7, 'WEB-00084-1', $charged, $charged + 31 * 86_400, 'https://shop.test/x', $charged));
        } finally {
            date_default_timezone_set($zone);
        }

        $html = (string) $this->transport->sent[0]->getHtmlBody();
        self::assertStringContainsString('Der Betrag wurde am 29. Oktober 2026 über Kreditkarte abgebucht.', $html);
        self::assertStringNotContainsString('CONSHO.', $html);

        $copy = (string) $this->transport->sent[1]->getHtmlBody();
        self::assertStringContainsString('über Kreditkarte am 29. Oktober 2026 abgebucht', $copy);
        self::assertStringNotContainsString('Auf Rechnung', $copy);
        self::assertStringNotContainsString('CONSHO.', $copy);
    }

    public function testRechnungZumAboAufRechnungSagtNichtsVomAbbuchen(): void
    {
        $this->mail()->subscriptionInvoice(7, 'WEB-00084-1', 1_793_288_184, 1_795_966_584, 'https://shop.test/x');

        self::assertStringNotContainsString('abgebucht', (string) $this->transport->sent[0]->getHtmlBody());
        self::assertStringContainsString('Auf Rechnung', (string) $this->transport->sent[1]->getHtmlBody());
    }

    public function testRechnungZumAboOhneAusgestellteRechnungGehtTrotzdemHinaus(): void
    {
        $this->source->invoiceRow = null;

        self::assertTrue($this->mail()->subscriptionInvoice(7, 'WEB-00084-1', 1_793_288_184, 1_795_966_584, 'https://shop.test/x'));
        self::assertSame([], $this->transport->sent[0]->getAttachments());
        self::assertStringNotContainsString('Anhang', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * Abo-5 (P37): „ruht" mit offener Rechnung im Anhang, Zahlungsziel und
     * offenem Betrag, sachlich — keine Mahnung, keine Drohung. Kopie an den
     * Betreiber, ohne Anhang.
     */
    public function testAboRuhtMitOffenerRechnungUndBetrag(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->source->order['paymentDueAt'] = (new \DateTimeImmutable('2026-11-12 23:59:59'))->getTimestamp();
        $this->source->documents[14] = ['id' => 14, 'documentNumber' => 'RE-00014', 'payload' => []];

        try {
            self::assertTrue($this->mail()->subscriptionSuspended(7, 'WEB-00084-1', '1.2000', 'https://shop.test/bestellung?consho_order=abc'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(2, $this->transport->sent, 'Kunde und Betreiber');

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Ihr Abo WEB-00084-1 ruht', $mail->getSubject());
        self::assertStringContainsString('12. November 2026', $html);
        self::assertStringContainsString('1,20', $html);
        self::assertStringContainsString('ruht Ihr Zugang', $html);
        self::assertStringContainsString('Rechnung RE-00014', $html);
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertStringNotContainsString('Mahnung', $html);
        self::assertCount(1, $mail->getAttachments(), 'die offene Rechnung');

        $copy = $this->transport->sent[1];
        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertStringContainsString('WEB-00084-1', (string) $copy->getSubject());
        self::assertStringContainsString('kündigt nicht', (string) $copy->getHtmlBody());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
        self::assertSame([], $copy->getAttachments());
    }

    /**
     * Abo pausieren (P110): von wann bis wann, was es bedeutet; Kopie an den
     * Betreiber.
     */
    public function testPauseBestaetigt(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            $from = (new \DateTimeImmutable('2026-10-29 16:36'))->getTimestamp();
            $until = (new \DateTimeImmutable('2026-12-29 16:36'))->getTimestamp();
            self::assertTrue($this->mail()->subscriptionPaused(7, 'WEB-00084-1', $from, $until, 'https://shop.test/x'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(2, $this->transport->sent, 'Kunde und Betreiber');
        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();
        self::assertSame('Pause für Ihr Abo WEB-00084-1 bestätigt', $mail->getSubject());
        self::assertStringContainsString('vom 29. Oktober 2026 bis 29. Dezember 2026', $html);
        self::assertStringContainsString('Laufzeit verschiebt sich', $html);
        self::assertStringNotContainsString('CONSHO.', $html);

        $copy = $this->transport->sent[1];
        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertStringContainsString('WEB-00084-1', (string) $copy->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
    }

    /**
     * Nachlese E5 (Michael, 2026-10-09: „E5 Infomail umsetzen"): ein
     * Einmalkauf über Ziel und Nachfrist — die offene Rechnung im Anhang,
     * sachlich, nur an den Kunden.
     */
    public function testEinmalkaufRuhtMitOffenerRechnung(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->source->order['paymentDueAt'] = (new \DateTimeImmutable('2026-11-12 23:59:59'))->getTimestamp();
        $this->source->documents[14] = ['id' => 14, 'documentNumber' => 'RE-00014', 'payload' => []];

        try {
            self::assertTrue($this->mail()->purchaseSuspended(7, '30.0000', 'https://shop.test/bestellung?consho_order=abc'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(1, $this->transport->sent, 'nur der Kunde');

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertStringContainsString('ruhen', (string) $mail->getSubject());
        self::assertStringContainsString('12. November 2026', $html);
        self::assertStringContainsString('30,00', $html);
        self::assertStringContainsString('Rechnung RE-00014', $html);
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=abc', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertStringNotContainsString('CONSHO.', (string) $mail->getSubject());
        self::assertCount(1, $mail->getAttachments(), 'die offene Rechnung');
    }

    public function testEinmalkaufWiederVerfuegbarNurAnDenKunden(): void
    {
        self::assertTrue($this->mail()->purchaseResumed(7, 'https://shop.test/x'));

        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertStringContainsString('wieder zur Verfügung', (string) $mail->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $mail->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $mail->getHtmlBody());
    }

    public function testAboWiederAktivNurAnDenKunden(): void
    {
        self::assertTrue($this->mail()->subscriptionResumed(7, 'WEB-00084-1', 'https://shop.test/x'));

        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertSame('Ihr Abo WEB-00084-1 ist wieder aktiv', $mail->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $mail->getHtmlBody());
        // Auch nach einer Stornierung richtig: kein „Danke für Ihre Zahlung".
        self::assertStringNotContainsString('Zahlung', (string) $mail->getHtmlBody());
    }

    /**
     * E7: eine ausstehende Abbuchung — Betrag, Zeitraum, Zahlungsart, und
     * dass die Rechnung mit der Zahlung kommt. Nur an den Kunden.
     */
    public function testAbbuchungGestartetMitBetragZeitraumUndZahlungsart(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->source->order['paymentMethodTitle'] = 'SEPA-Lastschrift';

        try {
            $from = (new \DateTimeImmutable('2026-10-29 17:00'))->getTimestamp();
            self::assertTrue($this->mail()->subscriptionChargePending(7, 'WEB-00084-1', $from, $from + 30 * 86_400, 'https://shop.test/x'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();
        self::assertSame('Abbuchung zu Ihrem Abo WEB-00084-1', $mail->getSubject());
        self::assertStringContainsString('vom 29. Oktober 2026 bis 28. November 2026', $html);
        self::assertStringContainsString('Den Betrag von 13,90', $html);
        self::assertStringContainsString('über SEPA-Lastschrift zur Abbuchung', $html);
        self::assertStringContainsString('erhalten Sie die Rechnung', $html);
        self::assertStringContainsString('https://shop.test/x', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function erinnerungen(): iterable
    {
        yield 'endet' => ['ends', ['endet das Abo', 'Neu abschließen', 'https://shop.test/produkt/e-book.html'], ['kündigen Sie']];
        yield 'gleiche Laufzeit' => ['sameTerm', ['verlängert es sich um 1 Jahr', 'bis spätestens 17. Januar 2027'], ['Neu abschließen']];
        yield 'unbefristet' => ['indefinite', ['unbefristet zu den bisherigen Bedingungen', 'mit 14 Tagen Frist', 'bis spätestens 17. Januar 2027'], ['zu derzeit']];
        // Variante A: eine Altregel ohne monatliche Zahlung endet.
        yield 'Altregel ohne monatliche Zahlung' => ['endsNoMonthly', ['endet das Abo', 'Neu abschließen'], ['zu derzeit']];
    }

    /**
     * Abo-6 (P42, P43): die Erinnerung vor dem Ende der Mindestlaufzeit, je
     * nach dem, was danach kommt — nur an den Kunden.
     *
     * @param list<string> $contains
     * @param list<string> $absent
     *
     * @dataProvider erinnerungen
     */
    public function testErinnerungVorDemEndeDerMindestlaufzeit(string $kind, array $contains, array $absent): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            self::assertTrue($this->mail()->subscriptionReminder(7, [
                'subscription' => 'WEB-00084-1', 'kind' => $kind, 'product' => 'E-Book Kräuterkunde',
                'termEndsAt' => (new \DateTimeImmutable('2027-01-31 10:15'))->getTimestamp(),
                'cancelUntil' => (new \DateTimeImmutable('2027-01-17 10:15'))->getTimestamp(),
                'termLength' => 1, 'termUnit' => 'year', 'noticeDays' => 14,
                'productUrl' => 'https://shop.test/produkt/e-book.html',
            ], 'https://shop.test/bestellung?consho_order=abc'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(1, $this->transport->sent, 'nur an den Kunden (P43)');
        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Ihr Abo WEB-00084-1: Die Mindestlaufzeit endet am 31. Januar 2027', $mail->getSubject());
        self::assertStringContainsString('E-Book Kräuterkunde', $html);
        self::assertStringNotContainsString('CONSHO.', $html);

        foreach ($contains as $text) {
            self::assertStringContainsString($text, $html);
        }

        foreach ($absent as $text) {
            self::assertStringNotContainsString($text, $html);
        }
    }

    /**
     * Abo-7b (P71): „Lizenz verlängern" auf „Meine Abos" statt „Neu
     * abschließen" auf der Produktseite — wo der Shop die Seite nennt.
     */
    public function testVorDemEndeVerlinktDieErinnerungMeineAbos(): void
    {
        $reminder = [
            'subscription' => 'WEB-00084-1', 'kind' => 'ends', 'product' => 'E-Book',
            'termEndsAt' => 1_801_000_000, 'cancelUntil' => 1_800_000_000, 'termLength' => 1, 'termUnit' => 'year', 'noticeDays' => 1,
            'productUrl' => 'https://shop.test/produkt.html',
        ];

        self::assertTrue($this->mail()->subscriptionReminder(7, $reminder + ['renewUrl' => 'https://shop.test/meine-abos.html'], 'https://shop.test/x'));
        $html = (string) $this->transport->sent[0]->getHtmlBody();
        self::assertStringContainsString('href="https://shop.test/meine-abos.html"', $html);
        self::assertStringContainsString('Lizenz verlängern', $html);
        self::assertStringNotContainsString('produkt.html', $html);

        // Ohne die Seite: wie bisher die Produktseite.
        self::assertTrue($this->mail()->subscriptionReminder(7, $reminder + ['renewUrl' => null], 'https://shop.test/x'));
        $html = (string) $this->transport->sent[1]->getHtmlBody();
        self::assertStringContainsString('href="https://shop.test/produkt.html"', $html);
        self::assertStringNotContainsString('Lizenz verlängern', $html);
    }

    /**
     * Abo-7, P62 (§ 312k Abs. 4 BGB): Inhalt der Kündigung, Datum und Uhrzeit
     * des Eingangs und das Vertragsende; Kopie an den Betreiber.
     */
    public function testKuendigungsbestaetigungMitEingangInhaltUndEnde(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            self::assertTrue($this->mail()->cancellationReceived(7, $this->cancellation([
                'requestedAt' => (new \DateTimeImmutable('2026-11-01 00:00'))->getTimestamp(),
                'earliestApplied' => true,
            ]), 'https://shop.test/x'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(2, $this->transport->sent);

        $mail = $this->transport->sent[0];
        $html = (string) $mail->getHtmlBody();

        self::assertSame('Eingangsbestätigung: Kündigung Ihres Abos WEB-00084-1', $mail->getSubject());
        self::assertStringContainsString('2. Oktober 2026', $html);
        self::assertStringContainsString('14:03:05', $html, 'die Uhrzeit, nicht nur das Datum');
        self::assertStringContainsString('WEB-00084-1-K1', $html);
        self::assertStringContainsString('E-Book &lt;Kräuter&gt;', $html);
        self::assertStringContainsString('ordentliche Kündigung', $html);
        self::assertStringContainsString('1. November 2026', $html, 'der gewünschte Zeitpunkt');
        self::assertStringContainsString('endet durch diese Kündigung am 15. Januar 2027', $html);
        self::assertStringContainsString('vor dem frühestmöglichen', $html);
        self::assertStringNotContainsString('Kündigungsgrund', $html);
        self::assertStringNotContainsString('noch voll berechnet', $html, 'Ende = frühestmöglich: nichts zu sagen');
        self::assertStringNotContainsString('CONSHO.', $html);

        $copy = $this->transport->sent[1];
        self::assertSame('office@web.example.net', $copy->getTo()[0]->getAddress());
        self::assertSame('Kündigung eingegangen: Abo WEB-00084-1', $copy->getSubject());
        self::assertStringNotContainsString('CONSHO.', (string) $copy->getHtmlBody());
    }

    /**
     * Review Abo-7, S1: ein späteres Ende als das frühestmögliche — die Mail
     * sagt, dass angebrochene Perioden noch voll berechnet werden.
     */
    public function testEinSpaeteresEndeSagtWasEsKostet(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            self::assertTrue($this->mail()->cancellationReceived(7, $this->cancellation([
                'effectiveAt' => (new \DateTimeImmutable('2027-02-01 00:00'))->getTimestamp(),
            ]), ''));
        } finally {
            date_default_timezone_set($zone);
        }

        $html = (string) $this->transport->sent[0]->getHtmlBody();
        self::assertStringContainsString('nach dem frühestmöglichen Ende (15. Januar 2027', $html);
        self::assertStringContainsString('noch voll berechnet', $html);
    }

    public function testAusserordentlicheKuendigungOhneEndeMitGrund(): void
    {
        self::assertTrue($this->mail()->cancellationReceived(7, $this->cancellation([
            'kind' => 'extraordinary', 'reason' => "Umzug\n<b>", 'effectiveAt' => null,
        ]), ''));

        $html = (string) $this->transport->sent[0]->getHtmlBody();

        self::assertStringContainsString('außerordentliche Kündigung', $html);
        self::assertStringContainsString('Umzug<br />', $html);
        self::assertStringContainsString('&lt;b&gt;', $html);
        self::assertStringContainsString('zum frühestmöglichen Zeitpunkt', $html, 'kein Wunschtermin');
        self::assertStringContainsString('entscheiden wir', $html);
        self::assertStringNotContainsString('endet durch diese Kündigung', $html);
        self::assertStringNotContainsString('Bestellung ansehen', $html);

        $copy = (string) $this->transport->sent[1]->getHtmlBody();
        self::assertStringContainsString('im Backend annehmen', $copy);
    }

    public function testEntscheidungUeberDieAusserordentlicheKuendigung(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            $end = (new \DateTimeImmutable('2026-10-31 00:00'))->getTimestamp();
            self::assertTrue($this->mail()->cancellationDecided(7, 'WEB-00084-1-K1', 'WEB-00084-1', true, $end, '', 'https://shop.test/x'));
            self::assertTrue($this->mail()->cancellationDecided(7, 'WEB-00084-1-K1', 'WEB-00084-1', false, null, "Kein wichtiger Grund.\n<i>", 'https://shop.test/x'));
        } finally {
            date_default_timezone_set($zone);
        }

        self::assertCount(2, $this->transport->sent, 'keine Kopie: der Betreiber hat entschieden');

        [$accepted, $rejected] = $this->transport->sent;
        self::assertSame('Ihre Kündigung des Abos WEB-00084-1 ist angenommen', $accepted->getSubject());
        self::assertStringContainsString('am 31. Oktober 2026', (string) $accepted->getHtmlBody());

        self::assertSame('Ihre Kündigung des Abos WEB-00084-1', $rejected->getSubject());
        self::assertStringContainsString('Kein wichtiger Grund.<br />', (string) $rejected->getHtmlBody());
        self::assertStringContainsString('&lt;i&gt;', (string) $rejected->getHtmlBody());
        self::assertStringContainsString('läuft zu den bisherigen Bedingungen weiter', (string) $rejected->getHtmlBody());
        self::assertStringNotContainsString('CONSHO.', (string) $rejected->getHtmlBody());
    }

    public function testEinAusgefallenerServerWirftNicht(): void
    {
        $this->transport->fail = true;

        self::assertFalse($this->mail()->paymentReceived(7, null, 'https://shop.test/x', true));
        self::assertFalse($this->mail()->shipped(7, ['carrier' => '', 'trackingNumber' => '', 'items' => [], 'complete' => true], null, 'https://shop.test/x'));
        self::assertFalse($this->mail()->subscriptionInvoice(7, 'WEB-00084-1', 1_793_288_184, 1_795_966_584, 'https://shop.test/x'));
        self::assertFalse($this->mail()->subscriptionSuspended(7, 'WEB-00084-1', '1.2000', 'https://shop.test/x'));
        self::assertFalse($this->mail()->subscriptionResumed(7, 'WEB-00084-1', 'https://shop.test/x'));
        self::assertFalse($this->mail()->purchaseSuspended(7, '1.2000', 'https://shop.test/x'));
        self::assertFalse($this->mail()->purchaseResumed(7, 'https://shop.test/x'));
        self::assertFalse($this->mail()->subscriptionPaused(7, 'WEB-00084-1', 1_793_288_184, 1_795_966_584, 'https://shop.test/x'));
        self::assertFalse($this->mail()->cancellationReceived(7, $this->cancellation(), 'https://shop.test/x'));
        self::assertFalse($this->mail()->cancellationDecided(7, 'WEB-00084-1-K1', 'WEB-00084-1', false, null, 'Nein.', 'https://shop.test/x'));
        self::assertNotEmpty($this->logger->errors);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{number: string, subscription: string, product: string, receivedAt: int, kind: string, reason: string, name: string, email: string, requestedAt: int|null, effectiveAt: int|null, earliestApplied: bool, earliestAt: int|null}
     */
    private function cancellation(array $overrides = []): array
    {
        $zone = new \DateTimeZone('Europe/Vienna');

        /** @var array{number: string, subscription: string, product: string, receivedAt: int, kind: string, reason: string, name: string, email: string, requestedAt: int|null, effectiveAt: int|null, earliestApplied: bool, earliestAt: int|null} */
        return [
            'number' => 'WEB-00084-1-K1',
            'subscription' => 'WEB-00084-1',
            'product' => 'E-Book <Kräuter>',
            'receivedAt' => (new \DateTimeImmutable('2026-10-02 14:03:05', $zone))->getTimestamp(),
            'kind' => 'ordinary',
            'reason' => '',
            'name' => 'Erika Muster',
            'email' => 'kundin@example.org',
            'requestedAt' => null,
            'effectiveAt' => (new \DateTimeImmutable('2027-01-15 10:00', $zone))->getTimestamp(),
            'earliestApplied' => false,
            'earliestAt' => (new \DateTimeImmutable('2027-01-15 10:00', $zone))->getTimestamp(),
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{number: string, receivedAt: int, source: string, name: string, email: string, scope: string, items: list<array{quantity: string, name: string}>, part: string, message: string}
     */
    private function withdrawal(int $receivedAt, array $overrides = []): array
    {
        /** @var array{number: string, receivedAt: int, source: string, name: string, email: string, scope: string, items: list<array{quantity: string, name: string}>, part: string, message: string} */
        return [
            'number' => 'WEB-00054-W1',
            'receivedAt' => $receivedAt,
            'source' => 'orderPage',
            'name' => 'Erika Muster',
            'email' => 'kundin@example.org',
            'scope' => 'all',
            'items' => [],
            'part' => '',
            'message' => '',
            ...$overrides,
        ];
    }

    /** WG-4: the vouchers of a test that has some, on a database of their own. */
    private \Doctrine\DBAL\Connection|null $voucherConnection = null;

    private function voucherPdf(): \Webwerkwien\ContaoConshoBundle\Document\Pdf\PdfRendererInterface
    {
        $pdf = $this->createStub(\Webwerkwien\ContaoConshoBundle\Document\Pdf\PdfRendererInterface::class);
        $pdf->method('render')->willReturn('%PDF-voucher');

        return $pdf;
    }

    private function mail(MailFrames|null $frames = null): OrderEventMail
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

        $pdfs = new class implements DocumentPdfInterface {
            public function pdf(int $documentId): array
            {
                return ['pdf' => '%PDF-'.$documentId, 'fileName' => 'RE-00016.pdf', 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
            }
        };

        return new OrderEventMail(
            $this->source,
            new MailRenderer($twig, $translator),
            new RecipientResolver(),
            $factory,
            new DocumentPayloadBuilder(),
            new InvoiceView(new MoneyFormatter(), new DocumentPayloadBuilder()),
            $pdfs,
            new \Webwerkwien\ContaoConshoBundle\Voucher\VoucherDocument($this->voucherConnection ?? $this->createStub(\Doctrine\DBAL\Connection::class), $twig, $translator, $this->voucherPdf(), new MoneyFormatter()),
            $this->logger,
            new MoneyFormatter(),
            $frames,
        );
    }
}

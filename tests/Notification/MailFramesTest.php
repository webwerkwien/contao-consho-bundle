<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrame;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrames;
use Webwerkwien\ContaoConshoBundle\Notification\MailImageFile;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;

/**
 * GARAN part 2 (L1, L2, L4): header and footer of the customer mails from
 * the fields the invoice already has — logo, company, legal form, address,
 * footer in the language of the mail.
 */
class MailFramesTest extends TestCase
{
    private const LOGO = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_translation');
    }

    public function testFirmaRechtsformAnschriftUndFusszeile(): void
    {
        $this->connection->insert('tl_consho_shop', [
            'id' => 1,
            'fallbackLanguage' => 'de',
            'invoiceCompany' => 'Müller &amp; Söhne',
            'invoiceLegalForm' => 'GmbH',
            'invoiceStreet' => 'Musterstraße 1',
            'invoicePostal' => '1100',
            'invoiceCity' => 'Wien',
            'invoiceCountry' => 'at',
            'invoiceFooter' => "FN 123456a, HG Wien\nIBAN AT00 0000",
        ]);

        $frame = $this->frames()->of(1, 'de');

        self::assertSame('Müller & Söhne GmbH', $frame->company, 'dekodiert — die Vorlage escaped');
        self::assertSame('Musterstraße 1, 1100 Wien, Österreich', $frame->address);
        self::assertSame("FN 123456a, HG Wien\nIBAN AT00 0000", $frame->footer);
        self::assertNull($frame->logo);
        self::assertSame('Austria', substr($this->frames()->of(1, 'en')->address, -7), 'das Land in der Sprache der Mail');
    }

    public function testDieFusszeileInDerSpracheDerMail(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'fallbackLanguage' => 'de', 'invoiceFooter' => 'Firmenbuch FN 1a']);
        // German is the main language; the only translation is into English.
        $this->connection->insert('tl_consho_translation', ['ptable' => 'tl_consho_shop', 'pid' => 1, 'language' => 'en', 'invoiceFooter' => 'Company register FN 1a']);

        self::assertSame('Company register FN 1a', $this->frames()->of(1, 'en')->footer);
        self::assertSame('Firmenbuch FN 1a', $this->frames()->of(1, 'fr')->footer, 'ohne Übersetzung der Haupttext');
    }

    public function testDasLogoEingebettetMitEigenerBreiteBisZweihundert(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'fallbackLanguage' => 'de', 'invoiceCompany' => 'Muster', 'invoiceLogo' => self::LOGO]);
        // A real PNG of 1 × 1 pixel.
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $images = $this->createMock(MailImageFile::class);
        $images->expects(self::once())->method('read')->with(self::LOGO)->willReturn(['name' => 'logo.png', 'contents' => $png, 'contentType' => 'image/png']);

        $frame = $this->frames($images)->of(1, 'de');
        $cid = 'logo-'.bin2hex(self::LOGO).'.png';

        self::assertSame(['cid' => $cid, 'width' => 1], $frame->view()['logo'], 'nie aufgeblasen');

        $email = $frame->embedInto((new Email())->html('<img src="cid:'.$cid.'">'));
        $parts = $email->getAttachments();
        self::assertCount(1, $parts);
        self::assertSame($cid, $parts[0]->getName());
        self::assertSame('inline', $parts[0]->getDisposition());
    }

    public function testOhneLesbareDateiKeinLogoUndOhneShopEinLeererRahmen(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'fallbackLanguage' => 'de', 'invoiceLogo' => self::LOGO]);
        $images = $this->createStub(MailImageFile::class);
        $images->method('read')->willReturn(null);

        self::assertNull($this->frames($images)->of(1, 'de')->logo);
        self::assertEquals(new MailFrame('', '', ''), $this->frames()->of(99, 'de'));
    }

    private function frames(MailImageFile|null $images = null): MailFrames
    {
        return new MailFrames($this->connection, new TranslationStorage($this->connection), new ShopLanguages($this->connection), $images ?? $this->createStub(MailImageFile::class));
    }
}

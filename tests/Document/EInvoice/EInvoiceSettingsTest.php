<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\EInvoice;

use Contao\DataContainer;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentSource;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\EInvoice\EInvoiceIssueSource;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\EInvoiceListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block R (R10, R12): wann eine Rechnung E-Rechnung ist, und was der Shop
 * dafür braucht.
 */
class EInvoiceSettingsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'tstamp' => 1, 'title' => 'Testshop', 'invoiceCompany' => 'Muster', 'invoiceCountry' => 'AT', 'vatId' => 'ATU12345678', 'invoiceContactEmail' => '']);
        $GLOBALS['TL_LANG']['tl_consho_shop']['eInvoiceIncomplete'] = 'Fehlt: %s';
        $GLOBALS['TL_LANG']['tl_consho_shop']['invoiceContactEmail'] = ['Kontakt-E-Mail für Rechnungen', ''];
        $GLOBALS['TL_LANG']['CONSHO']['eInvoiceFields'] = ['invoiceContactEmail' => 'Kontakt-E-Mail für Rechnungen'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testEinschaltenNurMitAllenPflichtangaben(): void
    {
        $this->expectExceptionMessage('Fehlt: Kontakt-E-Mail für Rechnungen');

        $this->listener([])('1', $this->dc());
    }

    public function testDieAngabeAusDemselbenFormularZaehlt(): void
    {
        self::assertSame('1', $this->listener(['invoiceContactEmail' => 'rechnung@example.org'])('1', $this->dc()));
        // Ausschalten geht immer.
        self::assertSame('', $this->listener([])('', $this->dc()));
    }

    /**
     * CW-5 (I2): auf der Konsole gibt es kein Formular — der Befehl, der die
     * E-Rechnung zusammen mit der Kontaktadresse einschaltet, geht durch.
     */
    public function testAufDerKonsoleZaehltDerAktiveDatensatz(): void
    {
        $listener = new EInvoiceListener($this->connection, new RequestStack());

        self::assertSame('1', $listener('1', $this->console(['invoiceContactEmail' => 'rechnung@example.org'])));

        $this->expectExceptionMessage('Fehlt: Kontakt-E-Mail für Rechnungen');
        $listener('1', $this->console([]));
    }

    /**
     * Beim Anlegen gibt es noch nichts Gespeichertes; der aktive Datensatz
     * zählt allein — und nur mit den Pflichtfeldern.
     */
    public function testBeimAnlegenAufDerKonsole(): void
    {
        $listener = new EInvoiceListener($this->connection, new RequestStack());
        $all = ['invoiceCompany' => 'Neu', 'invoiceCountry' => 'DE', 'vatId' => 'DE1', 'invoiceContactEmail' => 'r@example.org'];

        self::assertSame([], $listener->missing(0, $all));
        self::assertSame(['vatId'], $listener->missing(0, [...$all, 'vatId' => '']));
    }

    public function testImFormularGewinntDasFormular(): void
    {
        // Der aktive Datensatz der Maske kennt nur die bisher verarbeiteten
        // Felder — das Formular ist das letzte Wort.
        self::assertSame(['invoiceContactEmail'], $this->listener(['invoiceContactEmail' => ''])->missing(1, ['invoiceContactEmail' => 'alt@example.org']));
    }

    public function testDerBefundMeldetEineSpaeterGeleerteAngabe(): void
    {
        self::assertCount(0, $this->findings());

        $this->connection->update('tl_consho_shop', ['eInvoice' => 1], ['id' => 1]);
        $issues = $this->findings();

        self::assertSame(ConfigurationFinding::EInvoiceIncomplete, $issues[0]->finding);
        self::assertSame(['shop' => 'Testshop', 'fields' => 'Kontakt-E-Mail für Rechnungen'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    /**
     * Nur an Geschäftskunden — Nettogruppe oder geprüfte UID, beim Kauf
     * eingefroren.
     */
    public function testNurDerGeschaeftskundeBekommtEineERechnung(): void
    {
        $builder = new DocumentPayloadBuilder();

        self::assertTrue($builder->build(['businessCustomer' => '1'], [], ['eInvoice' => true])['eInvoice']);
        self::assertFalse($builder->build(['businessCustomer' => '0'], [], ['eInvoice' => true])['eInvoice']);
        self::assertFalse($builder->build(['businessCustomer' => '1'], [], ['eInvoice' => false])['eInvoice']);
    }

    public function testDasZahlungsmittelNachArtDerZahlungsart(): void
    {
        self::assertSame('58', DbalDocumentSource::meansCode('prepayment', ['iban' => 'AT61']));
        // Review R, F6 (BR-61): ohne Konto kein Überweisungscode.
        self::assertSame('ZZZ', DbalDocumentSource::meansCode('invoice', []));
        self::assertSame('58', DbalDocumentSource::meansCode('invoice', ['iban' => 'AT61']));
        self::assertSame('10', DbalDocumentSource::meansCode('cod', []));
        self::assertSame('ZZZ', DbalDocumentSource::meansCode('stripe', []));
    }

    /**
     * @return list<\Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue>
     *
     * @phpstan-impure
     */
    private function findings(): array
    {
        return (new EInvoiceIssueSource($this->connection))->issues();
    }

    /**
     * @param array<string, string> $posted
     */
    private function listener(array $posted): EInvoiceListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/contao', 'POST', $posted));

        return new EInvoiceListener($this->connection, $requests);
    }

    /**
     * Wie contao-ais RecordDataContainer: ein DC_Table mit dem Datensatz
     * samt der Werte, die gespeichert werden.
     *
     * @param array<string, string> $values
     */
    private function console(array $values): DC_Table
    {
        $stored = $this->connection->fetchAssociative('SELECT * FROM tl_consho_shop WHERE id = 1') ?: [];
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 1 : null);
        $dc->method('getActiveRecord')->willReturn([...$stored, ...$values]);

        return $dc;
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 1 : null);

        return $dc;
    }
}

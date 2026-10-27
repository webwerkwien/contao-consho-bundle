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
 * Block R (R10, R12): when an invoice is an e-invoice, and what the shop
 * needs for it.
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
        // Switching off always works.
        self::assertSame('', $this->listener([])('', $this->dc()));
    }

    /**
     * CW-5 (I2): on the console there is no form — the command that switches
     * on the e-invoice together with the contact address goes through.
     */
    public function testAufDerKonsoleZaehltDerAktiveDatensatz(): void
    {
        $listener = new EInvoiceListener($this->connection, new RequestStack());

        self::assertSame('1', $listener('1', $this->console(['invoiceContactEmail' => 'rechnung@example.org'])));

        $this->expectExceptionMessage('Fehlt: Kontakt-E-Mail für Rechnungen');
        $listener('1', $this->console([]));
    }

    /**
     * When creating, nothing is stored yet; the active record alone counts —
     * and only with the mandatory fields.
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
        // The form's active record only knows the fields processed so far
        // — the form has the last word.
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

    public function testOhneConshoRechnungenKeinBefundZurERechnung(): void
    {
        // Block R (R19): the XML travels in Consho's invoice.
        $this->connection->update('tl_consho_shop', ['eInvoice' => 1], ['id' => 1]);
        $source = new EInvoiceIssueSource($this->connection, new \Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy($this->connection, new \Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders(new \Symfony\Component\DependencyInjection\ServiceLocator([]))));

        self::assertCount(1, $source->issues());

        $this->connection->update('tl_consho_shop', ['invoicing' => 'off'], ['id' => 1]);
        self::assertSame([], $source->issues());
    }

    /**
     * Only to business customers — net group or verified VAT ID, frozen at
     * purchase.
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
        // R (BR-61): no account, no credit transfer code.
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
     * Like contao-ai's RecordDataContainer: a DC_Table with the record
     * including the values being saved.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\NumberRangeListener;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeChange;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * CW-5 (I4): "Next free number" in the form and in the commands.
 * Shop 1 has the invoices RE-00001 to RE-00003 and RE-2027-00001, the
 * orders B-10 and B-14 (gap), and shop 2 has the invoice RE-00009.
 */
class NumberRangeChangeTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_number_range');

        foreach (['RE-00001', 'RE-00002', 'RE-00003', 'RE-2027-00001'] as $number) {
            $this->connection->insert('tl_consho_document', ['shop' => 1, 'type' => 'invoice', 'documentNumber' => $number]);
        }

        $this->connection->insert('tl_consho_document', ['shop' => 1, 'type' => 'deliveryNote', 'documentNumber' => 'LS-00050']);
        $this->connection->insert('tl_consho_document', ['shop' => 2, 'type' => 'invoice', 'documentNumber' => 'RE-00009']);
        $this->connection->insert('tl_consho_order', ['shop' => 1, 'orderNumber' => 'B-10']);
        $this->connection->insert('tl_consho_order', ['shop' => 1, 'orderNumber' => 'B-14']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testFortsetzenUndHoeherGehenImmer(): void
    {
        self::assertNull($this->change()->collision(1, 'invoice', 'RE-', 3));
        self::assertNull($this->change()->collision(1, 'invoice', 'RE-', 4711), 'Umstieg aus einem anderen System');
    }

    public function testUnterDieVergebeneNummerNicht(): void
    {
        self::assertSame('RE-00003', $this->change()->collision(1, 'invoice', 'RE-', 2));
        self::assertSame('RE-00003', $this->change()->collision(1, 'invoice', 'RE-', 0));
    }

    /**
     * The turn of the year: new prefix, counter 0. And "RE-2027-00001" does
     * not count under "RE-" — the rest is not a number.
     */
    public function testNeuesPraefixBeginntBeiNull(): void
    {
        self::assertNull($this->change()->collision(1, 'invoice', 'RE-2028-', 0));
        self::assertSame('RE-2027-00001', $this->change()->collision(1, 'invoice', 'RE-2027-', 0));
    }

    public function testGrossKleinschreibungTrenntKeineNummern(): void
    {
        self::assertSame('RE-00003', $this->change()->collision(1, 'invoice', 're-', 0));
    }

    /**
     * Order numbers have gaps: a counter in the gap collides a few orders
     * later.
     */
    public function testBestellnummernMitLuecke(): void
    {
        self::assertSame('B-14', $this->change()->collision(1, 'order', 'B-', 11));
        self::assertNull($this->change()->collision(1, 'order', 'B-', 14));
    }

    /**
     * The highest number is named, not the last one read: as text "B-100"
     * comes before "B-20".
     */
    public function testGenanntWirdDieHoechsteNummer(): void
    {
        $this->connection->insert('tl_consho_order', ['shop' => 5, 'orderNumber' => 'B-100']);
        $this->connection->insert('tl_consho_order', ['shop' => 5, 'orderNumber' => 'B-20']);

        self::assertSame('B-100', $this->change()->collision(5, 'order', 'B-', 11));
    }

    public function testNurDieserShop(): void
    {
        self::assertNull($this->change()->collision(3, 'invoice', 'RE-', 0));
        self::assertSame('RE-00009', $this->change()->collision(2, 'invoice', 'RE-', 0));
        self::assertNull($this->change()->collision(0, 'invoice', 'RE-', 0));
    }

    /**
     * The four document types are one number space:
     * a credit note with "RE-" at 0 printed RE-00001 next to the invoice
     * RE-00001 — and for VAT purposes a credit note is an invoice.
     * The order number stays a series of its own.
     */
    public function testEinPraefixGiltUeberAlleBelegarten(): void
    {
        self::assertSame('RE-00003', $this->change()->collision(1, 'creditNote', 'RE-', 0), 'die Rechnungen RE-00001…3');
        self::assertSame('LS-00050', $this->change()->collision(1, 'invoice', 'LS-', 0), 'der Lieferschein LS-00050');

        $this->connection->insert('tl_consho_document', ['shop' => 1, 'type' => 'cancellation', 'documentNumber' => 'RE-00007']);
        self::assertSame('RE-00007', $this->change()->collision(1, 'invoice', 'RE-', 3), 'der Storno RE-00007 sperrt auch die Rechnung');
        self::assertNull($this->change()->collision(1, 'deliveryNote', 'RE-', 7));
        self::assertNull($this->change()->collision(1, 'order', 'RE-', 0), 'Bestellnummern zählen getrennt');
    }

    /**
     * A prefix with characters a LIKE would interpret ("_", "%", "\"):
     * the decision is made in PHP, so they count as themselves.
     */
    public function testZeichenDieLikeDeutet(): void
    {
        foreach (['R_1-00007', 'R%2-00008', 'R\\3-00009', 'R!4-00010', 'RX1-00099'] as $number) {
            $this->connection->insert('tl_consho_document', ['shop' => 6, 'type' => 'invoice', 'documentNumber' => $number]);
        }

        self::assertSame('R_1-00007', $this->change()->collision(6, 'invoice', 'R_1-', 0), '„_" nicht als Platzhalter: RX1-00099 zählt nicht');
        self::assertSame('R%2-00008', $this->change()->collision(6, 'invoice', 'R%2-', 0));
        self::assertSame('R\\3-00009', $this->change()->collision(6, 'invoice', 'R\\3-', 0));
        self::assertSame('R!4-00010', $this->change()->collision(6, 'invoice', 'R!4-', 0));
    }

    /**
     * Lower-casing can change the length of a character ("İ" → "i̇").
     * The rest is cut from the same form as the prefix.
     */
    public function testKleinschreibungMitAndererLaenge(): void
    {
        $this->connection->insert('tl_consho_document', ['shop' => 7, 'type' => 'invoice', 'documentNumber' => 'İ-7']);

        self::assertSame('İ-7', $this->change()->collision(7, 'invoice', mb_strtolower('İ-'), 0));
    }

    public function testDieMaskeLehntMitDerNummerAb(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['issued'] = 'Vergeben: %s';
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 7, 'pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'current' => 3]);

        $listener = new NumberRangeListener($this->change());

        self::assertSame(['current' => 5], $listener->refuseIssuedNumber(['current' => 5], $dc));
        self::assertSame(['prefix' => 'RE-2028-', 'current' => 0], $listener->refuseIssuedNumber(['prefix' => 'RE-2028-', 'current' => 0], $dc));

        // Existing numbers below the assigned number do not block a change
        // that does not touch the counter.
        $legacy = $this->createStub(DataContainer::class);
        $legacy->method('getCurrentRecord')->willReturn(['id' => 8, 'pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'current' => 1]);
        self::assertSame(['padding' => 6], $listener->refuseIssuedNumber(['padding' => 6], $legacy), 'nur die Stellen: nichts zu prüfen');

        $this->expectExceptionMessage('Vergeben: RE-00003');
        $listener->refuseIssuedNumber(['current' => 1], $dc);
    }

    /**
     * From the order series into the documents: checked against the documents.
     */
    public function testDieArtZuWechselnPrueftDieNeueArt(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['issued'] = 'Vergeben: %s';
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 7, 'pid' => 1, 'type' => 'order', 'prefix' => 'RE-', 'current' => 1]);

        $this->expectExceptionMessage('Vergeben: RE-00003');
        (new NumberRangeListener($this->change()))->refuseIssuedNumber(['type' => 'deliveryNote'], $dc);
    }

    /**
     * Changing only the prefix is also a change that checks (CW-6):
     * "RE-2028-" at 0 to "RE-" would fall below RE-00003.
     */
    public function testNurDasPraefixZuAendernPrueft(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['issued'] = 'Vergeben: %s';
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 7, 'pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-2028-', 'current' => 0]);

        $this->expectExceptionMessage('Vergeben: RE-00003');
        (new NumberRangeListener($this->change()))->refuseIssuedNumber(['prefix' => 'RE-'], $dc);
    }

    /**
     * Two document types on one prefix meet later, however the counters stand
     * today: invoices at 3, credit notes from 50 — at 51 doubled.
     * Upper/lower case and spaces do not separate a prefix; the order number
     * has its own space.
     */
    public function testZweiBelegartenTeilenKeinPraefix(): void
    {
        $this->connection->insert('tl_consho_number_range', ['id' => 1, 'pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'current' => 3]);
        $this->connection->insert('tl_consho_number_range', ['id' => 2, 'pid' => 1, 'type' => 'order', 'prefix' => 'B-', 'current' => 14]);
        $this->connection->insert('tl_consho_number_range', ['id' => 3, 'pid' => 2, 'type' => 'creditNote', 'prefix' => 'GS-', 'current' => 0]);

        self::assertSame('invoice', $this->change()->sharedPrefix(1, 9, 'creditNote', ' re- '));
        self::assertNull($this->change()->sharedPrefix(1, 1, 'invoice', 'RE-'), 'der eigene Kreis');
        self::assertNull($this->change()->sharedPrefix(1, 9, 'creditNote', 'B-'), 'die Bestellnummer zählt nicht');
        self::assertNull($this->change()->sharedPrefix(1, 9, 'order', 'RE-'), 'eine Bestellreihe teilt keinen Belegraum');
        self::assertNull($this->change()->sharedPrefix(1, 9, 'creditNote', 'GS-'), 'GS- gehört einem anderen Shop');

        $GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['shared'] = 'Geteilt: %s mit %s';
        $GLOBALS['TL_LANG']['tl_consho_number_range']['types']['invoice'] = 'Rechnung';
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 4, 'pid' => 1, 'type' => 'creditNote', 'prefix' => 'GS-', 'current' => 0]);

        $this->expectExceptionMessage('Geteilt: RE- mit Rechnung');
        (new NumberRangeListener($this->change()))->refuseIssuedNumber(['prefix' => 'RE-', 'current' => 50], $dc);
    }

    /**
     * The way through the form (CW-6): the range itself does not share its
     * prefix with itself, and whoever switches the type is checked with the
     * new type.
     */
    public function testDieMaskePrueftDasPraefixMitIdUndNeuerArt(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_number_range']['errors']['shared'] = 'Geteilt: %s mit %s';
        $this->connection->insert('tl_consho_number_range', ['id' => 1, 'pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'current' => 3]);
        $this->connection->insert('tl_consho_number_range', ['id' => 5, 'pid' => 1, 'type' => 'order', 'prefix' => 'RE-', 'current' => 3]);
        $listener = new NumberRangeListener($this->change());

        $own = $this->createStub(DataContainer::class);
        $own->method('getCurrentRecord')->willReturn(['id' => 1, 'pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'current' => 3]);
        self::assertSame(['current' => 4], $listener->refuseIssuedNumber(['current' => 4], $own), 'der Rechnungskreis selbst');

        $switch = $this->createStub(DataContainer::class);
        $switch->method('getCurrentRecord')->willReturn(['id' => 5, 'pid' => 1, 'type' => 'order', 'prefix' => 'RE-', 'current' => 3]);

        $this->expectExceptionMessage('Geteilt: RE- mit invoice');
        $listener->refuseIssuedNumber(['type' => 'creditNote'], $switch);
    }

    private function change(): NumberRangeChange
    {
        return new NumberRangeChange($this->connection);
    }
}

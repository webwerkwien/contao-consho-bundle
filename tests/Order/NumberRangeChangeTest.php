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
 * CW-5 (I4), Michael 2026-10-11: „Nächste Nummer frei" in Maske und Befehlen.
 * Shop 1 hat die Rechnungen RE-00001 bis RE-00003 und RE-2027-00001, die
 * Bestellungen B-10 und B-14 (Lücke) und in Shop 2 die Rechnung RE-00009.
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
     * Der Jahreswechsel: neues Präfix, Zähler 0. Und „RE-2027-00001" zählt
     * nicht unter „RE-" — der Rest ist keine Zahl.
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
     * Bestellnummern haben Lücken: ein Zähler in der Lücke kollidiert ein
     * paar Bestellungen später.
     */
    public function testBestellnummernMitLuecke(): void
    {
        self::assertSame('B-14', $this->change()->collision(1, 'order', 'B-', 11));
        self::assertNull($this->change()->collision(1, 'order', 'B-', 14));
    }

    /**
     * Genannt wird die höchste Nummer, nicht die zuletzt gelesene: Als Text
     * steht „B-100" vor „B-20".
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
     * Die vier Belegarten sind ein Nummernraum (Michael, 2026-10-12, CW-6):
     * Eine Gutschrift mit „RE-" bei 0 druckte RE-00001 neben die Rechnung
     * RE-00001 — und eine Gutschrift ist umsatzsteuerlich eine Rechnung.
     * Die Bestellnummer bleibt eine eigene Reihe.
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
     * Ein Präfix mit Zeichen, die ein LIKE deuten würde („_", „%", „\"):
     * Entschieden wird in PHP, also zählen sie als sie selbst.
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
     * Kleinschreibung kann die Länge eines Zeichens ändern („İ" → „i̇").
     * Der Rest wird aus derselben Form geschnitten wie das Präfix.
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

        // Ein Altbestand unter der vergebenen Nummer sperrt keine Änderung,
        // die den Zähler nicht berührt.
        $legacy = $this->createStub(DataContainer::class);
        $legacy->method('getCurrentRecord')->willReturn(['id' => 8, 'pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'current' => 1]);
        self::assertSame(['padding' => 6], $listener->refuseIssuedNumber(['padding' => 6], $legacy), 'nur die Stellen: nichts zu prüfen');

        $this->expectExceptionMessage('Vergeben: RE-00003');
        $listener->refuseIssuedNumber(['current' => 1], $dc);
    }

    /**
     * Aus der Bestellreihe in die Belege: geprüft wird gegen die Belege.
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
     * Nur das Präfix zu ändern ist auch eine Änderung, die prüft (Review
     * CW-6, K3): „RE-2028-" bei 0 auf „RE-" fiele unter RE-00003.
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
     * Zwei Belegarten auf einem Präfix treffen sich später, wie die Zähler
     * heute auch stehen: Rechnungen bei 3, Gutschriften ab 50 — bei 51 doppelt.
     * Groß-/Kleinschreibung und Leerzeichen trennen kein Präfix; die
     * Bestellnummer hat ihren eigenen Raum.
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
     * Der Weg durch die Maske (Review CW-6, zweiter Durchgang, S1): Der eigene
     * Kreis teilt sein Präfix nicht mit sich selbst, und wer die Art wechselt,
     * wird mit der neuen Art geprüft.
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

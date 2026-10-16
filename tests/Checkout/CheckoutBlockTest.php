<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutBlock;

class CheckoutBlockTest extends TestCase
{
    /**
     * Michaels Entscheidung vom 2026-09-22 im Wortlaut: **„Kaufen bleibt
     * eigen."** Bei zwei Schritten (Adresse + Versand + Zahlung) | (Prüfen und
     * kaufen), bei drei (Adresse) | (Versand + Zahlung) | (Prüfen und kaufen).
     *
     * Der Grund steht neben der Erwartung, weil eine Tabelle aus Aufzählungen
     * sonst nur sagt, was der Code tut, und nicht, was jemand wollte: § 8 FAGG
     * will die wesentlichen Angaben unmittelbar über dem Knopf, und ein
     * eigener Schritt bringt das ohne weiteres Zutun zustande.
     */
    public function testKaufenBleibtEinEigenerSchritt(): void
    {
        foreach ([2, 3, 4] as $count) {
            $steps = CheckoutBlock::steps($count);

            self::assertSame([CheckoutBlock::Review], end($steps), \sprintf('%d Schritte', $count));
            self::assertCount($count, $steps);
        }
    }

    public function testEinSchrittZeigtAllesAufEinerSeite(): void
    {
        self::assertSame(
            [[CheckoutBlock::Address, CheckoutBlock::Shipping, CheckoutBlock::Payment, CheckoutBlock::Review]],
            CheckoutBlock::steps(1),
        );
    }

    public function testZweiSchritteFassenAllesVorDemKaufenZusammen(): void
    {
        self::assertSame(
            [
                [CheckoutBlock::Address, CheckoutBlock::Shipping, CheckoutBlock::Payment],
                [CheckoutBlock::Review],
            ],
            CheckoutBlock::steps(2),
        );
    }

    public function testDreiSchritteTrennenDieAdresseAb(): void
    {
        self::assertSame(
            [
                [CheckoutBlock::Address],
                [CheckoutBlock::Shipping, CheckoutBlock::Payment],
                [CheckoutBlock::Review],
            ],
            CheckoutBlock::steps(3),
        );
    }

    /**
     * Jeder Baustein kommt in jeder Aufteilung genau einmal vor — sonst
     * verlöre eine Einstellung stillschweigend eine Frage, etwa die nach der
     * Zahlungsart.
     */
    #[DataProvider('schrittzahlen')]
    public function testJedeAufteilungEnthaeltAlleVierBausteineGenauEinmal(int $count): void
    {
        $seen = array_merge(...CheckoutBlock::steps($count));

        self::assertEqualsCanonicalizing(CheckoutBlock::cases(), $seen);
        self::assertCount(4, $seen);
    }

    /**
     * Eine Zahl aus einem Feld im Backend kann alles sein. Sie wird
     * eingefangen, nicht zurückgewiesen: ein Checkout, der wegen einer 7 in
     * einer Spalte gar nicht erscheint, ist die schlechtere Antwort.
     */
    #[DataProvider('unsinnigeZahlen')]
    public function testEineUnsinnigeSchrittzahlWirdEingefangen(int $count, int $erwartet): void
    {
        self::assertCount($erwartet, CheckoutBlock::steps($count));
    }

    /**
     * 🔴 Im Review vom 2026-09-22 gefunden: Die Schrittnamen waren
     * durchnummeriert und stimmten nur bei EINEM Schritt. Bei dreien las ein
     * Screenreader „Versand und Zahlung" über dem Kaufknopf.
     *
     * Seitdem kommt der Name aus den Bausteinen — und dieser Test hält ihn an
     * die Sprachdatei: Jede Gruppe, die irgendeine Aufteilung erzeugt, braucht
     * dort einen Satz. Ohne ihn zeigt Contao den Schlüssel.
     */
    #[DataProvider('sprachen')]
    public function testJedeMoeglicheGruppeHatEinenText(string $sprache): void
    {
        // Beide Sprachen, nicht nur Deutsch: ein fehlender englischer Satz
        // zeigt dem Besucher den Schlüssel.
        $GLOBALS['TL_LANG'] = [];
        include \dirname(__DIR__, 2).'/contao/languages/'.$sprache.'/default.php';

        /** @var array<string, string> $labels */
        $labels = $GLOBALS['TL_LANG']['CONSHO']['checkout']['step'] ?? [];
        $fehlend = [];

        foreach ([1, 2, 3, 4] as $count) {
            foreach (CheckoutBlock::steps($count) as $blocks) {
                $key = CheckoutBlock::labelOf($blocks);

                if (!isset($labels[$key])) {
                    $fehlend[$key] = $count;
                }
            }
        }

        self::assertSame([], $fehlend, $sprache.': Diese Bausteingruppen haben keinen Namen.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sprachen(): iterable
    {
        yield 'deutsch' => ['de'];
        yield 'englisch' => ['en'];
    }

    public function testDerNameKommtAusDenBausteinenUndNichtAusDerNummer(): void
    {
        // Der dritte Schritt von dreien ist „Prüfen und kaufen" — vorher hieß
        // er „Versand und Zahlung", weil er der dritte war.
        $drei = CheckoutBlock::steps(3);

        self::assertSame('review', CheckoutBlock::labelOf($drei[2]));
        self::assertSame('shipping_payment', CheckoutBlock::labelOf($drei[1]));
        self::assertSame('address', CheckoutBlock::labelOf($drei[0]));

        // Und bei vieren ist der dritte die Zahlung, nicht der Kauf.
        self::assertSame('payment', CheckoutBlock::labelOf(CheckoutBlock::steps(4)[2]));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function schrittzahlen(): iterable
    {
        yield 'ein Schritt' => [1];
        yield 'zwei' => [2];
        yield 'drei' => [3];
        yield 'vier' => [4];
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function unsinnigeZahlen(): iterable
    {
        yield 'null' => [0, 1];
        yield 'negativ' => [-3, 1];
        yield 'zu viele' => [9, 4];
    }
}

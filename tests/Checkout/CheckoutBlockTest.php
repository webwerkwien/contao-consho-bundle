<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutBlock;

class CheckoutBlockTest extends TestCase
{
    /**
     * **Buying stays a step of its own.** With two steps (address + shipping +
     * payment) | (review and buy), with three (address) | (shipping + payment) |
     * (review and buy).
     *
     * The reason stands next to the expectation, because a table of enums
     * otherwise only says what the code does, not what someone wanted: § 8 FAGG
     * wants the essential information directly above the button, and a step of
     * its own achieves that without further effort.
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
     * Every building block occurs exactly once in every split — otherwise a
     * setting would silently lose a question, such as the one about the
     * payment method.
     */
    #[DataProvider('schrittzahlen')]
    public function testJedeAufteilungEnthaeltAlleVierBausteineGenauEinmal(int $count): void
    {
        $seen = array_merge(...CheckoutBlock::steps($count));

        self::assertEqualsCanonicalizing(CheckoutBlock::cases(), $seen);
        self::assertCount(4, $seen);
    }

    /**
     * A number from a field in the backend can be anything. It is caught, not
     * refused: a checkout that does not appear at all because of a 7 in a
     * column is the worse answer.
     */
    #[DataProvider('unsinnigeZahlen')]
    public function testEineUnsinnigeSchrittzahlWirdEingefangen(int $count, int $erwartet): void
    {
        self::assertCount($erwartet, CheckoutBlock::steps($count));
    }

    /**
     * 🔴 The step names used to be numbered and were right only for ONE step.
     * With three, a screen reader read "Shipping and payment" above the buy
     * button.
     *
     * Since then the name comes from the building blocks — and this test holds
     * it to the language file: every group that any split produces needs a
     * sentence there. Without it Contao shows the key.
     */
    #[DataProvider('sprachen')]
    public function testJedeMoeglicheGruppeHatEinenText(string $sprache): void
    {
        // Both languages, not just German: a missing English sentence
        // shows the visitor the key.
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
        // The third step of three is "Review and buy" — before, it was called
        // "Shipping and payment" because it was the third.
        $drei = CheckoutBlock::steps(3);

        self::assertSame('review', CheckoutBlock::labelOf($drei[2]));
        self::assertSame('shipping_payment', CheckoutBlock::labelOf($drei[1]));
        self::assertSame('address', CheckoutBlock::labelOf($drei[0]));

        // And with four, the third is the payment, not the purchase.
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

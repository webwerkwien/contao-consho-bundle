<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CartRuleCurrencyListener;

/**
 * Review H, L5: Betrag oder Mindestbestellwert ohne Währung — die Regel gälte
 * nirgends. Abgelehnt am Feld, aus dem abgeschickten Formular gelesen.
 */
final class CartRuleCurrencyListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function ohneWaehrungAbgelehnt(): iterable
    {
        yield 'fester Betrag' => [['effect' => 'amount', 'minimumOrder' => '0']];
        yield 'Mindestbestellwert' => [['effect' => 'percent', 'minimumOrder' => '50']];
        yield 'Mindestbestellwert mit Komma' => [['effect' => 'freeShipping', 'minimumOrder' => '0,50']];
    }

    /**
     * @param array<string, string> $post
     */
    #[DataProvider('ohneWaehrungAbgelehnt')]
    public function testOhneWaehrungAbgelehnt(array $post): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener($post)->save('  ', $this->createStub(DataContainer::class));
    }

    public function testProzentOhneMindestwertBrauchtKeine(): void
    {
        self::assertSame('', $this->listener(['effect' => 'percent', 'minimumOrder' => '0'])->save('', $this->createStub(DataContainer::class)));
        self::assertSame('', $this->listener(['effect' => 'percent', 'minimumOrder' => ''])->save('', $this->createStub(DataContainer::class)));
    }

    public function testMitWaehrungGrossGeschrieben(): void
    {
        self::assertSame('EUR', $this->listener(['effect' => 'amount', 'minimumOrder' => '50'])->save(' eur ', $this->createStub(DataContainer::class)));
    }

    public function testBeimAutomatischenAbschickenKeinePruefung(): void
    {
        // Zweites Review H, L-A: der Wechsel auf „Betrag" schickt ab, bevor die Währung getippt ist.
        self::assertSame('', $this->listener(['effect' => 'amount', 'minimumOrder' => '50', 'SUBMIT_TYPE' => 'auto'])->save('', $this->createStub(DataContainer::class)));
    }

    public function testOhneAbgeschicktesFormularKeinePruefung(): void
    {
        self::assertSame('', (new CartRuleCurrencyListener(new RequestStack()))->save('', $this->createStub(DataContainer::class)));
        // Mehrere bearbeiten: die Felder heißen dort je Datensatz anders.
        self::assertSame('', $this->listener(['effect_12' => 'amount'])->save('', $this->createStub(DataContainer::class)));
    }

    /**
     * @param 'de'|'en' $language
     */
    #[DataProvider('languages')]
    public function testDieMeldungStehtInDerSprachdatei(string $language): void
    {
        $saved = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/tl_consho_cart_rule.php';

        self::assertIsString($GLOBALS['TL_LANG']['tl_consho_cart_rule']['currencyMissing'] ?? null);

        $GLOBALS['TL_LANG'] = $saved;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    /**
     * @param array<string, string> $post
     */
    private function listener(array $post): CartRuleCurrencyListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/contao', 'POST', $post));

        return new CartRuleCurrencyListener($requests);
    }
}

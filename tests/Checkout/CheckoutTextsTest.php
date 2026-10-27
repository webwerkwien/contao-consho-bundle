<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutProblem;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * The checkout template assembles its keys ("CONSHO.checkout.problem." ~
 * problem) or picks them by condition — no compile test sees whether they
 * exist. A missing key would reach the customer as the bare key, of all
 * places in the sentence that tells them why they cannot buy (Abo-2).
 */
class CheckoutTextsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sprachen(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    #[DataProvider('sprachen')]
    public function testJedesProblemHatEinenSatz(string $language): void
    {
        $translator = new ContaoLikeTranslator();

        foreach (CheckoutProblem::cases() as $problem) {
            $key = 'CONSHO.checkout.problem.'.$problem->value;

            self::assertNotSame($key, $translator->trans($key, [], 'contao_default', $language), $problem->name.' in '.$language);
        }
    }

    #[DataProvider('sprachen')]
    public function testJedeWiderrufsartHatIhrenZustimmungssatz(string $language): void
    {
        $translator = new ContaoLikeTranslator();

        foreach (['digitalConsent', 'digitalConsentService', 'digitalConsentHint', 'digitalConsentHintService', 'digitalConsentHintMixed'] as $key) {
            $full = 'CONSHO.checkout.'.$key;

            self::assertNotSame($full, $translator->trans($full, [], 'contao_default', $language), $key.' in '.$language);
        }
    }

    /**
     * DL: the note below the box for each mix — the two lines of the real
     * template rendered (read from the file, not copied).
     */
    public function testDerHinweisNenntWasImKorbLiegt(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../contao/templates/content_element/consho_checkout.html.twig');
        preg_match('/\{% set consent_kinds = .*?%\}/', $source, $set);
        preg_match('/\{\{ \(consent_kinds == .*?\}\}/', $source, $hint);
        self::assertNotEmpty($set);
        self::assertNotEmpty($hint);

        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['h' => $set[0].$hint[0]]));
        $twig->addFilter(new \Twig\TwigFilter('trans', static fn (string $key): string => $key));
        $render = static fn (array $kinds): string => $twig->render('h', ['digital_consent_kinds' => $kinds]);

        self::assertSame('CONSHO.checkout.digitalConsentHint', $render(['content']));
        self::assertSame('CONSHO.checkout.digitalConsentHintService', $render(['service']));
        self::assertSame('CONSHO.checkout.digitalConsentHintMixed', $render(['content', 'service']));
        self::assertSame('CONSHO.checkout.digitalConsentHint', $render([]));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceRuleOptionsListener;

/**
 * Abnahme H auf c5, 2026-10-05: Die Maske der Preisregeln starb mit einem
 * 500er beim ersten neuen Datensatz. Die DCA nannte den Listener als
 * `[Klasse::class, 'methode']`; Contao löst das über den Container auf, und
 * der Dienst des Bundles ist privat. Jeder Test baute die Klasse selbst
 * (Regel 25) — keiner den Container.
 *
 * Callbacks des Bundles hängen deshalb am Attribut `#[AsCallback]`, das den
 * Dienst registriert. Dieser Test lehnt die Array-Form in den DCAs ab.
 */
final class DcaCallbackRegistrationTest extends TestCase
{
    public function testKeineDcaNenntEineKlasseAlsArrayCallback(): void
    {
        $found = [];

        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            if (preg_match_all("/\\[\\s*\\\\?[A-Z][A-Za-z\\\\]*::class\\s*,\\s*'[A-Za-z]+'\\s*\\]/", (string) file_get_contents($file), $matches)) {
                foreach ($matches[0] as $match) {
                    $found[] = basename($file).': '.$match;
                }
            }
        }

        self::assertSame([], $found);
    }

    public function testDieAuswahlDerRegelmaskenHaengtAmAttribut(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(PriceRuleOptionsListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $targets[] = $arguments['table'].':'.$arguments['target'].'→'.$method->getName();
            }
        }

        sort($targets);

        self::assertSame([
            'tl_consho_cart_rule:fields.products.options→products',
            'tl_consho_price_rule:fields.products.options→products',
            'tl_consho_price_rule:fields.propertyOptions.options→propertyOptions',
        ], $targets);
    }
}

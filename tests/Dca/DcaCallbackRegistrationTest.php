<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceRuleOptionsListener;

/**
 * H: the price rule form died with a 500 on its first new record. The DCA
 * named the listener as `[Listener::class, 'method']`; Contao resolves that
 * through the container, and the bundle's service is private. Every test
 * built the class itself — none let the container build it.
 *
 * The bundle's callbacks therefore hang on the `#[AsCallback]` attribute,
 * which registers the service. This test rejects the array form in the DCAs.
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

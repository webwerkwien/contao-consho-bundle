<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;

/**
 * Die Andockstelle für den Zeilenpreis. C-4 tauscht hier genau eine
 * Implementierung — die, die die Kundengruppe des Mitglieds kennt. Das hält
 * nur, solange CartPricer allein die Schnittstelle kennt und der Alias dort
 * steht, wo C-4 ihn umbiegt. Gleiches Muster wie FilterCountsSeamTest.
 */
class CartLinePriceSeamTest extends TestCase
{
    public function testDerRechnerFragtNurDieSchnittstelle(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            (new \ReflectionMethod(CartPricer::class, '__construct'))->getParameters(),
        );

        self::assertContains(CartLinePriceSource::class, $types);
        self::assertNotContains(ArticlePriceResolver::class, $types, 'Mit dem Resolver im Konstruktor ließe sich die Preisquelle in C-4 nicht austauschen.');
    }

    public function testDerAliasStehtDaWoCVierIhnUmbiegt(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../../config/services.yaml')['services'] ?? [];

        self::assertSame(
            ['alias' => ArticlePriceResolver::class],
            $services[CartLinePriceSource::class] ?? null,
        );
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\Headline;

/**
 * Contao formats the operation's title with the ID after the page ran
 * (Backend::getBackendModule()); the page puts the order number in first.
 */
class HeadlineTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']['tl_x']);
    }

    public function testDieBestellnummerStattDerId(): void
    {
        $GLOBALS['TL_LANG']['tl_x']['moneyFlows'] = ['Geldflüsse', 'Saldo der Bestellung ID %s'];

        Headline::name('tl_x', 'moneyFlows', 'WEB-00012');

        // As Contao uses it: sprintf with the ID, which no longer appears.
        self::assertSame('Saldo der Bestellung WEB-00012', \sprintf($GLOBALS['TL_LANG']['tl_x']['moneyFlows'][1], '7'));
    }

    public function testProzentUndHtmlImBetreffBleibenText(): void
    {
        $GLOBALS['TL_LANG']['tl_x']['k'] = ['K', 'Zahlung ID %s'];

        Headline::name('tl_x', 'k', '100% <b>');

        self::assertSame('Zahlung 100% &lt;b&gt;', \sprintf($GLOBALS['TL_LANG']['tl_x']['k'][1], '7'));
    }

    public function testOhneIdImTitelBleibtErStehen(): void
    {
        $GLOBALS['TL_LANG']['tl_x']['k'] = ['K', 'Alle Zahlungen'];

        Headline::name('tl_x', 'k', 'WEB-1');
        Headline::name('tl_x', 'missing', 'WEB-1');

        self::assertSame('Alle Zahlungen', $GLOBALS['TL_LANG']['tl_x']['k'][1]);
        self::assertArrayNotHasKey('missing', $GLOBALS['TL_LANG']['tl_x']);
    }
}

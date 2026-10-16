<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\OrderItemLabelListener;

/**
 * DL4, Spec 6.4: die Art je Zeile in der Liste der Bestellung.
 */
class OrderItemLabelListenerTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../../contao/languages/de/tl_consho_order_item.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDieProduktzeileNenntIhreArt(): void
    {
        $label = (new OrderItemLabelListener())(['lineType' => 'product', 'kind' => 'service', 'performedAt' => null], 'Beratung × 1 = 120,00');

        self::assertSame('Beratung × 1 = 120,00 <span class="label-info">[Dienstleistung]</span>', $label);
    }

    public function testDieVersandzeileBleibtWieSieIst(): void
    {
        self::assertSame('Paket', (new OrderItemLabelListener())(['lineType' => 'shipping', 'kind' => ''], 'Paket'));
    }
}

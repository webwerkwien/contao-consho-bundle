<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionShowListener;

/**
 * Abo-3: the details showed quantity and net as "1.0000".
 */
class SubscriptionShowListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_LANG']['tl_consho_subscription']);
    }

    /**
     * Abo-4b, P98: the mandate only appears as "present" or "none" — the value
     * is the provider's key to the customer's account.
     */
    public function testDasMandatNurAlsVorhandenNieDerWert(): void
    {
        // A label other than the fallback value: that shows the translation takes effect.
        $GLOBALS['TL_LANG']['tl_consho_subscription'] = ['mandate' => ['Mandate', ''], 'mandatePresent' => 'present', 'mandateAbsent' => 'none'];

        // Contao leaves the field out (doNotShow); the row comes from the listener.
        $shown = (new SubscriptionShowListener())(['tl_consho_subscription' => [0 => []]], ['mandate' => 'cus_1|pm_2']);
        self::assertSame(['Mandate <small>mandate</small>' => 'present'], $shown['tl_consho_subscription'][0]);

        $shown = (new SubscriptionShowListener())(['tl_consho_subscription' => [0 => []]], ['mandate' => '']);
        self::assertSame(['Mandate <small>mandate</small>' => 'none'], $shown['tl_consho_subscription'][0]);
    }

    public function testDasMandatfeldIstDoNotShow(): void
    {
        $GLOBALS['TL_DCA'] = [];
        include __DIR__.'/../../../contao/dca/tl_consho_subscription.php';

        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_subscription']['fields']['mandate']['eval']['doNotShow'] ?? false);
        unset($GLOBALS['TL_DCA']);
    }

    public function testMengeUndNettoWieEinMenschSieSchreibt(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';

        // This is how Contao keys the rows: label, then the field name.
        $data = ['tl_consho_subscription' => [0 => [
            'Menge <small>quantity</small>' => '2.5000',
            'Netto je Lizenz <small>unitNet</small>' => '1.0000',
            'Abonummer <small>subscriptionNumber</small>' => 'WEB-00084-1',
        ]]];

        $shown = (new SubscriptionShowListener())($data, ['quantity' => '2.5000', 'unitNet' => '1.0000', 'currency' => 'EUR']);
        $row = array_map(static fn (mixed $v): string => str_replace(["\u{a0}", "\u{202f}"], ' ', (string) $v), $shown['tl_consho_subscription'][0]);

        self::assertSame('2,5', $row['Menge <small>quantity</small>']);
        self::assertSame('1,00 €', $row['Netto je Lizenz <small>unitNet</small>']);
        // Control case: other fields stay as Contao built them.
        self::assertSame('WEB-00084-1', $row['Abonummer <small>subscriptionNumber</small>']);
    }
}

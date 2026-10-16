<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionShowListener;

/**
 * Abnahme Abo-3 (2026-09-29): Die Details zeigten Menge und Netto als „1.0000".
 */
class SubscriptionShowListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_LANG']['tl_consho_subscription']);
    }

    /**
     * Abo-4b, P98: Das Mandat erscheint nur als „vorhanden" oder „keines" —
     * der Wert ist der Schlüssel des Anbieters zum Konto des Kunden.
     */
    public function testDasMandatNurAlsVorhandenNieDerWert(): void
    {
        // Ein anderes Label als der Rückfallwert: so zeigt sich, dass die Übersetzung greift.
        $GLOBALS['TL_LANG']['tl_consho_subscription'] = ['mandate' => ['Mandate', ''], 'mandatePresent' => 'present', 'mandateAbsent' => 'none'];

        // Contao lässt das Feld weg (doNotShow); die Zeile kommt vom Listener.
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

        // So schlüsselt Contao die Zeilen: Beschriftung, dann der Feldname.
        $data = ['tl_consho_subscription' => [0 => [
            'Menge <small>quantity</small>' => '2.5000',
            'Netto je Lizenz <small>unitNet</small>' => '1.0000',
            'Abonummer <small>subscriptionNumber</small>' => 'WEB-00084-1',
        ]]];

        $shown = (new SubscriptionShowListener())($data, ['quantity' => '2.5000', 'unitNet' => '1.0000', 'currency' => 'EUR']);
        $row = array_map(static fn (mixed $v): string => str_replace(["\u{a0}", "\u{202f}"], ' ', (string) $v), $shown['tl_consho_subscription'][0]);

        self::assertSame('2,5', $row['Menge <small>quantity</small>']);
        self::assertSame('1,00 €', $row['Netto je Lizenz <small>unitNet</small>']);
        // Gegenprobe: andere Felder bleiben, wie Contao sie gebaut hat.
        self::assertSame('WEB-00084-1', $row['Abonummer <small>subscriptionNumber</small>']);
    }
}

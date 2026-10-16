<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;

/**
 * Textprüfungen an der Vorlage der Bestellseite, wie CartTemplateTest: kein
 * Rendering, aber jedes Formular einzeln und jeder Schlüssel in beiden
 * Sprachdateien.
 */
class OrderTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_order.html.twig';

    public function testJedesFormularTraegtAnfrageTokenUndGenauEineHandlung(): void
    {
        preg_match_all('#<form\b.*?</form>#s', (string) file_get_contents(self::TEMPLATE), $matches);

        self::assertCount(11, $matches[0], '„Neuen Link anfordern" ohne gültigen Link (D-6, T4), der Knopf beim ersten Öffnen und „Vertrag widerrufen" daneben (D-2, E5), „Vertrag widerrufen" auf der Seite und die Erklärung (D-2), „Erneut versuchen" (C-11), ein Formular je Beleg-Format, eines für den Wechsel, „Herunterladen" (F-5) und je Stück eines Moduls Download und Knopf (LZ-1).');

        foreach ($matches[0] as $form) {
            /*
             * Abnahme D-2: „Vertrag widerrufen" ist ein Knopf in einem GET-
             * Formular — wie die übrigen Knöpfe vom Theme gestaltet, ändert
             * aber nichts. Kein Anfrage-Token (er landete in der Adresse),
             * dafür das Bestell-Token als Feld: Ein GET-Formular ersetzt die
             * Abfrage der Seitenadresse.
             */
            if (str_contains($form, 'method="get"')) {
                self::assertStringNotContainsString('REQUEST_TOKEN', $form);
                self::assertStringContainsString('name="consho_order"', $form);
                self::assertStringContainsString('name="consho_withdraw" value="1"', $form);

                continue;
            }

            self::assertSame(1, substr_count($form, 'name="REQUEST_TOKEN"'));
            self::assertSame(1, substr_count($form, 'name="consho_order_action"'));

            // Kein action-Attribut: Das Formular muss an die eigene Adresse
            // gehen, Token eingeschlossen.
            self::assertStringNotContainsString('action=', $form);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tabellen(): iterable
    {
        yield 'Bestellseite' => [self::TEMPLATE];
        yield 'Warenkorb' => [__DIR__.'/../../../contao/templates/content_element/consho_cart.html.twig'];
    }

    /**
     * Abnahme C-10: Das Demo-Theme blendet unter 640 px jedes `<th>` aus und
     * schreibt `attr(data-cellheadline)` vor jede Zelle. Ein Artikelname im
     * Zeilenkopf verschwand dort ganz, und jede Zelle begann mit „: ".
     * Also: im Rumpf keine Zeilenköpfe, und jede Zelle trägt ihre Überschrift.
     *
     * @dataProvider tabellen
     */
    public function testJedeZelleTraegtIhreUeberschriftUndDerRumpfKeinenZeilenkopf(string $file): void
    {
        // Without the Twig comments: they talk about <tbody> and <th> too, and
        // the pattern would start in the prose.
        $markup = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));

        preg_match_all('#<tbody>(.*?)</tbody>#s', $markup, $bodies);

        self::assertNotEmpty($bodies[1]);

        foreach ($bodies[1] as $body) {
            self::assertStringNotContainsString('<th', $body);

            preg_match_all('#<td\b[^>]*>#', $body, $cells);
            self::assertNotEmpty($cells[0]);

            foreach ($cells[0] as $cell) {
                self::assertStringContainsString('data-cellheadline="', $cell);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sprachen(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    /**
     * @dataProvider sprachen
     */
    public function testJederSchluesselStehtInDerSprachdatei(string $language): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/default.php';

        preg_match_all("#'(CONSHO\\.[A-Za-z.]+[A-Za-z])'#", (string) file_get_contents(self::TEMPLATE), $matches);

        $keys = array_unique($matches[1]);

        // Die zusammengesetzten Schlüssel, jeder Fall einzeln.
        foreach (PaymentState::cases() as $state) {
            $keys[] = 'CONSHO.orderPage.paymentStates.'.$state->value;
        }

        foreach (DeliveryState::cases() as $state) {
            $keys[] = 'CONSHO.orderPage.deliveryStates.'.$state->value;
        }

        foreach (['changed', 'notOpen', 'statusLocks', 'alreadyChanged', 'methodUnavailable', 'confirmMissing', 'paymentFailed', 'withdrawn', 'withdrawnUnconfirmed', 'withdrawClosed', 'withdrawInvalid', 'withdrawNothingSelected', 'withdrawNameMissing', 'afterWithdrawal'] as $notice) {
            $keys[] = 'CONSHO.orderPage.notice.'.$notice;
        }

        // C-11: die Sätze zur Online-Zahlung.
        foreach (['confirmed', 'reported', 'none', 'failed', 'cancelled'] as $online) {
            $keys[] = 'CONSHO.orderPage.online.'.$online;
        }

        foreach (['notOpen', 'statusLocks', 'alreadyChanged', 'afterWithdrawal'] as $block) {
            $keys[] = 'CONSHO.orderPage.changeBlocked.'.$block;
        }

        foreach (['pdf', 'html'] as $format) {
            $keys[] = 'CONSHO.orderPage.format.'.$format;
            $keys[] = 'CONSHO.orderPage.retrieve.'.$format;
        }

        foreach (['netTotal', 'taxTotal', 'grandTotal'] as $total) {
            $keys[] = 'CONSHO.invoice.'.$total;
        }

        foreach (['invoice', 'cancellation', 'creditNote'] as $type) {
            $keys[] = 'CONSHO.invoice.types.'.$type;
        }

        // DL3: die Überschrift der Berechtigungen nach ihrer Art.
        foreach (['entitlements', 'entitlementsServices', 'entitlementsMixed'] as $heading) {
            $keys[] = 'CONSHO.orderPage.'.$heading;
        }

        // DL3: die Sätze der Mails nach dem, was freigeschaltet wurde.
        foreach (['digital', 'service', 'mixed'] as $what) {
            $keys[] = 'CONSHO.mail.paymentReceived.ready.'.$what;
            $keys[] = 'CONSHO.mail.digitalReleased.'.$what.'.subject';
            $keys[] = 'CONSHO.mail.digitalReleased.'.$what.'.intro';

            foreach (['now', 'afterPayment', 'afterPeriod', 'afterBoth'] as $when) {
                $keys[] = 'CONSHO.mail.confirmation.'.$what.'.'.$when;
            }
        }

        foreach ($keys as $key) {
            $value = $GLOBALS['TL_LANG'];

            foreach (explode('.', $key) as $part) {
                $value = \is_array($value) ? ($value[$part] ?? null) : null;
            }

            // Ein Präfix wie 'CONSHO.orderPage.notice.' endet auf einen Punkt
            // und wird von der Suche oben nicht erfasst.
            self::assertIsString($value, \sprintf('%s fehlt in %s.', $key, $language));
        }

        unset($GLOBALS['TL_LANG']);
    }
}

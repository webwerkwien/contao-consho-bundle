<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;

/**
 * Text checks on the order page template, like CartTemplateTest: no
 * rendering, but every form on its own and every key in both language
 * files.
 */
class OrderTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_order.html.twig';

    public function testJedesFormularTraegtAnfrageTokenUndGenauEineHandlung(): void
    {
        preg_match_all('#<form\b.*?</form>#s', (string) file_get_contents(self::TEMPLATE), $matches);

        self::assertCount(12, $matches[0], '„Neuen Link anfordern" ohne gültigen Link (D-6, T4), der Knopf beim ersten Öffnen und „Vertrag widerrufen" daneben (D-2, E5), „Vertrag widerrufen" auf der Seite und die Erklärung (D-2), „Erneut versuchen" (C-11), ein Formular je Beleg-Format, eines für den Wechsel, „Herunterladen" (F-5), je Stück eines Moduls Download und Knopf (LZ-1) und „Kundenkonto anlegen" (K1).');

        foreach ($matches[0] as $form) {
            /*
             * D-2: "Vertrag widerrufen" (withdraw from contract) is a button in a
             * GET form — styled by the theme like the other buttons, but it changes
             * nothing. No request token (it ended up in the address), but the
             * order token as a field: a GET form replaces the query of the page
             * address.
             */
            if (str_contains($form, 'method="get"')) {
                self::assertStringNotContainsString('REQUEST_TOKEN', $form);
                self::assertStringContainsString('name="consho_order"', $form);
                self::assertStringContainsString('name="consho_withdraw" value="1"', $form);

                continue;
            }

            self::assertSame(1, substr_count($form, 'name="REQUEST_TOKEN"'));
            self::assertSame(1, substr_count($form, 'name="consho_order_action"'));

            // No action attribute: the form has to go to its own address, token
            // included.
            self::assertStringNotContainsString('action=', $form);
        }
    }

    /**
     * Nachlese stage 2: the Composer access of the licence package has two
     * lines (repository, then authentication). In a `<code>` without a line
     * break both commands ran into one line — what gets copied is a command
     * that does not work. `nl2br` escapes first, the text stays foreign text.
     */
    public function testEinMehrzeiligerModultextBehaeltSeineZeilen(): void
    {
        self::assertStringContainsString('<code class="consho-order__item-text">{{ item.text|nl2br }}</code>', (string) file_get_contents(self::TEMPLATE));

        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['t' => '{{ text|nl2br }}']));
        self::assertSame("a &lt;b&gt;<br />\nc", $twig->render('t', ['text' => "a <b>\nc"]));
    }

    /**
     * Block R: `invoices_by_mail` is false where the invoice comes from
     * elsewhere. `|default(true)` would turn that false into true — Twig's
     * default() replaces every empty value, false included — and the page
     * would promise documents by e-mail again.
     */
    public function testOhneRechnungsversandKommtDieAndereErklaerung(): void
    {
        $expression = "(invoices_by_mail ?? true) ? 'explanation' : 'explanationElsewhere'";

        self::assertStringContainsString("~ (".$expression.'))|trans',(string) file_get_contents(self::TEMPLATE));

        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['t' => '{{ '.$expression.' }}']));
        self::assertSame('explanationElsewhere', $twig->render('t', ['invoices_by_mail' => false]));
        self::assertSame('explanation', $twig->render('t', ['invoices_by_mail' => true]));
        self::assertSame('explanation', $twig->render('t', []));
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
     * C-10: the demo theme hides every `<th>` below 640 px and writes
     * `attr(data-cellheadline)` in front of every cell. An article name in the
     * row header vanished there entirely, and every cell started with ": ".
     * So: no row headers in the body, and every cell carries its heading.
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

        // The composed keys, each case on its own.
        foreach (PaymentState::cases() as $state) {
            $keys[] = 'CONSHO.orderPage.paymentStates.'.$state->value;
        }

        foreach (DeliveryState::cases() as $state) {
            $keys[] = 'CONSHO.orderPage.deliveryStates.'.$state->value;
        }

        // Block R: the explanation and notice where Consho mails no invoice.
        $keys[] = 'CONSHO.orderPage.change.explanationElsewhere';

        foreach (['changed', 'changedElsewhere', 'notOpen', 'statusLocks', 'alreadyChanged', 'methodUnavailable', 'confirmMissing', 'paymentFailed', 'withdrawn', 'withdrawnUnconfirmed', 'withdrawClosed', 'withdrawInvalid', 'withdrawNothingSelected', 'withdrawNameMissing', 'afterWithdrawal'] as $notice) {
            $keys[] = 'CONSHO.orderPage.notice.'.$notice;
        }

        // C-11: the sentences on online payment.
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

        // DL3: the heading of the entitlements by their kind.
        foreach (['entitlements', 'entitlementsServices', 'entitlementsMixed'] as $heading) {
            $keys[] = 'CONSHO.orderPage.'.$heading;
        }

        // DL3: the sentences of the mails by what was unlocked.
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

            // A prefix such as 'CONSHO.orderPage.notice.' ends in a dot and is not
            // caught by the search above.
            self::assertIsString($value, \sprintf('%s fehlt in %s.', $key, $language));
        }

        unset($GLOBALS['TL_LANG']);
    }
}

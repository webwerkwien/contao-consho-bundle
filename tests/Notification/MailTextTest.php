<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Notification\MailText;

class MailTextTest extends TestCase
{
    /**
     * Die erste echte Bestätigung (2026-09-25): „Net total€11.59" und
     * „Account holderMichael Morgenbesser" — Zellen ohne Trenner.
     */
    public function testTabellenzellenWerdenGetrenntUndZeilenUmgebrochen(): void
    {
        $text = MailText::fromHtml(
            '<table><tr><th scope="row">Net total</th><td>€11.59</td></tr><tr><th>VAT</th><td>€2.31</td></tr></table>',
        );

        self::assertStringContainsString("Net total | €11.59\nVAT | €2.31", $text);
    }

    /**
     * Mehrzeiliges Markup, wie es die Vorlage schreibt: Der Zeilenumbruch
     * zwischen zwei Zellen ist Quelltext, kein Inhalt.
     */
    public function testUmbruecheZwischenTabellenTagsZaehlenNicht(): void
    {
        $text = MailText::fromHtml("<table>\n    <tr>\n        <th>Net total</th>\n        <td style=\"x\">€11.59</td>\n    </tr>\n    <tr>\n        <th>VAT</th>\n        <td>€2.31</td>\n    </tr>\n</table>");

        self::assertSame("Net total | €11.59\nVAT | €2.31\n", $text);
    }

    /**
     * Wer nur Text liest, bekam „View your order" ohne Adresse.
     */
    public function testLinksBehaltenIhrZiel(): void
    {
        $text = MailText::fromHtml('<p><a href="https://shop.test/o?consho_order=abc&amp;x=1">View your order</a></p>');

        self::assertStringContainsString('View your order: https://shop.test/o?consho_order=abc&x=1', $text);
    }

    /**
     * Review 2026-09-25: Die Betreiberkopie verlinkt die Kundenadresse per
     * mailto: — im Text reicht die Adresse einmal.
     */
    public function testEinMailtoWiederholtDieAdresseNicht(): void
    {
        self::assertSame("kunde@example.org\n", MailText::fromHtml('<a href="mailto:kunde@example.org">kunde@example.org</a>'));
    }

    public function testLeerraumWirdZusammengefasst(): void
    {
        $text = MailText::fromHtml("<p>\n    Shipping method: Paket<br>        Payment method: Vorkasse\n</p>\n\n\n\n<p>Ende &amp; aus</p>");

        self::assertSame("Shipping method: Paket\nPayment method: Vorkasse\n\nEnde & aus\n", $text);
    }
}

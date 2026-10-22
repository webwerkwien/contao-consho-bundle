<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use PHPUnit\Framework\TestCase;

/**
 * GARAN Teil 2 (L3): JEDE Mail an Kunden trägt Kopf und Fuß, keine Kopie an
 * den Betreiber. Über die Dateien, damit eine neue Kundenmail ohne Rahmen
 * hier auffällt und nicht erst im Posteingang.
 */
class MailTemplateFrameTest extends TestCase
{
    public function testKundenmailsMitRahmenBetreiberkopienOhne(): void
    {
        $customer = 0;
        $operator = 0;

        foreach (glob(__DIR__.'/../../contao/templates/mail/consho_*.html.twig') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);
            $head = substr_count($source, "{% include '@Contao/mail/_frame_head.html.twig' %}");
            $foot = substr_count($source, "{% include '@Contao/mail/_frame_foot.html.twig' %}");

            if (str_ends_with($name, '_operator.html.twig')) {
                self::assertSame([0, 0], [$head, $foot], $name);
                ++$operator;

                continue;
            }

            self::assertSame([1, 1], [$head, $foot], $name);
            // OUTSIDE the white column, on the grey ground (Michael,
            // 2026-10-09): the head before its table opens, the foot after
            // it closes — and both inside the outer cell.
            $white = strpos($source, '<table role="presentation" width="600"');
            $whiteEnd = strpos($source, "</td></tr>\n</table>\n{% include '@Contao/mail/_frame_foot.html.twig' %}");
            self::assertNotFalse($white, $name);
            self::assertLessThan($white, strpos($source, '_frame_head'), $name);
            self::assertNotFalse($whiteEnd, $name.': der Fuß folgt direkt auf das Ende der weißen Tabelle');
            self::assertLessThan(strpos($source, '_frame_head'), strpos($source, '<tr><td align="center" style="padding: 24px 12px;">'), $name.': im grauen Rahmen, nicht davor');
            ++$customer;
        }

        // 23 since WA (2026-10-22): the confirmation of an unassigned withdrawal.
        self::assertSame(23, $customer);
        self::assertGreaterThan(0, $operator);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cron\DigitalReleaseCron;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * F-4: Der Cron fragt nur Bestellungen mit einer digitalen Zeile, die noch
 * keine gemeldete Lieferung deckt — und ein Fehler hält die anderen nicht auf.
 */
class DigitalReleaseCronTest extends TestCase
{
    public function testFragtNurOffeneDigitaleBestellungen(): void
    {
        $c = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_withdrawal', 'tl_consho_withdrawal_item', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($c, $table);
        }

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $id) {
            // orderNumber is unique in the DCA, as every real order has one.
            $c->insert('tl_consho_order', ['id' => $id, 'orderNumber' => 'WEB-'.$id]);
        }

        $c->update('tl_consho_order', ['cancelledAt' => 1], ['id' => 4]);
        $c->update('tl_consho_order', ['anonymizedAt' => 1], ['id' => 5]);

        $c->insert('tl_consho_order_item', ['id' => 10, 'pid' => 1, 'kind' => 'digital', 'quantity' => 2]); // offen
        $c->insert('tl_consho_order_item', ['id' => 20, 'pid' => 2, 'kind' => 'physical']);                  // nur körperlich
        $c->insert('tl_consho_order_item', ['id' => 30, 'pid' => 3, 'kind' => 'digital']);                  // schon freigegeben
        $c->insert('tl_consho_order_item', ['id' => 40, 'pid' => 4, 'kind' => 'digital']);                  // storniert
        $c->insert('tl_consho_order_item', ['id' => 50, 'pid' => 5, 'kind' => 'digital']);                  // anonymisiert
        $c->insert('tl_consho_order_item', ['id' => 60, 'pid' => 6, 'kind' => 'digital', 'quantity' => 2]); // Lieferung nicht gemeldet
        $c->insert('tl_consho_order_item', ['id' => 70, 'pid' => 7, 'kind' => 'digital']);                  // Widerruf offen (Review B2)
        $c->insert('tl_consho_withdrawal', ['pid' => 7, 'number' => 'W7', 'state' => 'received']);

        // Spec F, offene Punkte (Review K1): angenommen widerrufen wird nie
        // freigegeben — die Zeile war stündlich Kandidat ohne Ende. Was
        // übrig bleibt, bleibt Kandidat.
        $c->insert('tl_consho_order_item', ['id' => 80, 'pid' => 8, 'kind' => 'digital', 'quantity' => 2]); // ganz widerrufen
        $c->insert('tl_consho_withdrawal', ['id' => 8, 'pid' => 8, 'number' => 'W8', 'state' => 'accepted']);
        $c->insert('tl_consho_withdrawal_item', ['pid' => 8, 'orderItem' => 80, 'quantity' => 2, 'acceptedQuantity' => 2]);
        $c->insert('tl_consho_order_item', ['id' => 90, 'pid' => 9, 'kind' => 'digital', 'quantity' => 2]); // eins widerrufen
        $c->insert('tl_consho_withdrawal', ['id' => 9, 'pid' => 9, 'number' => 'W9', 'state' => 'accepted']);
        $c->insert('tl_consho_withdrawal_item', ['pid' => 9, 'orderItem' => 90, 'quantity' => 1, 'acceptedQuantity' => 1]);

        $c->insert('tl_consho_delivery', ['id' => 1, 'pid' => 3, 'reportedAt' => 100]);
        $c->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 30, 'quantity' => 1]);
        $c->insert('tl_consho_delivery', ['id' => 2, 'pid' => 1, 'reportedAt' => 100]);
        $c->insert('tl_consho_delivery_item', ['pid' => 2, 'orderItem' => 10, 'quantity' => 1]); // eins von zwei
        $c->insert('tl_consho_delivery', ['id' => 3, 'pid' => 6]);
        $c->insert('tl_consho_delivery_item', ['pid' => 3, 'orderItem' => 60, 'quantity' => 2]);

        $asked = [];
        $releases = $this->createMock(DigitalRelease::class);
        $releases->method('releaseDue')->willReturnCallback(static function (int $orderId, int $now, bool $notify) use (&$asked): int {
            $asked[] = [$orderId, $now, $notify];

            if (1 === $orderId) {
                throw new \RuntimeException('Vorrat gesperrt');
            }

            return 1;
        });

        (new DigitalReleaseCron($c, $releases, clock: static fn (): int => 500))();

        sort($asked);
        self::assertSame([[1, 500, true], [6, 500, true], [9, 500, true]], $asked, 'und nach dem Fehler bei 1 trotzdem 6; der Cron lässt „bereit" schicken; 8 ist ganz widerrufen');
    }
}

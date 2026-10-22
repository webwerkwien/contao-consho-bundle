<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Privacy;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cron\RetentionCron;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerDirectory;
use Webwerkwien\ContaoConshoBundle\Privacy\CustomerErasure;
use Webwerkwien\ContaoConshoBundle\Privacy\OrderAnonymizer;
use Webwerkwien\ContaoConshoBundle\Privacy\RetentionPolicy;

/**
 * D-5, L3/L5/L7 — ein Löschantrag: abgelaufene Bestellungen sofort
 * anonymisiert, laufende vermerkt und am Fristende anonymisiert; was kein
 * Beleg ist, sofort weg.
 */
final class CustomerErasureTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PrivacySchema::create();
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien', 'retentionYears' => 7, 'autoAnonymize' => 0]);
        $this->connection->insert('tl_member', ['id' => 5, 'firstname' => 'Erika', 'lastname' => 'Muster', 'email' => 'erika@example.org',
            'consho_shippingStreet' => 'Hauptstraße 1', 'consho_vatId' => 'ATU1', 'consho_confirmedEmail' => 'erika@example.org', 'consho_confirmedEmailAt' => 5]);

        $this->order(1, 5, 'erika@example.org', '2015-03-01');   // Frist Ende 2022 → sofort
        $this->order(2, 0, 'Erika@Example.org', '2024-03-01');   // Gast, gleiche Adresse, Frist läuft
        $this->order(3, 0, 'fremd@example.org', '2015-03-01');   // fremd
        $this->connection->insert('tl_consho_complaint', ['pid' => 2, 'note' => 'Frau Muster rief an']);
        $this->connection->insert('tl_consho_complaint', ['pid' => 3, 'note' => 'fremd']);

        $this->connection->insert('tl_consho_cart', ['id' => 10, 'member' => 5, 'checkoutData' => '{}']);
        $this->connection->insert('tl_consho_cart', ['id' => 11, 'member' => 0, 'checkoutData' => json_encode(['billing' => ['email' => 'erika@example.org']])]);
        $this->connection->insert('tl_consho_cart', ['id' => 12, 'member' => 0, 'checkoutData' => json_encode(['billing' => ['email' => 'nicht-erika@example.org.at']])]);
        $this->connection->insert('tl_consho_cart_item', ['pid' => 10]);

        $this->connection->insert('tl_opt_in', ['id' => 20, 'token' => 'cogo-x', 'email' => 'erika@example.org']);
        $this->connection->insert('tl_opt_in_related', ['pid' => 20, 'relTable' => 'tl_member', 'relId' => 5]);
        $this->connection->insert('tl_opt_in', ['id' => 21, 'token' => 'reg-y', 'email' => 'fremd@example.org']);

        $this->connection->insert('tl_version', ['fromTable' => 'tl_member', 'pid' => 5, 'data' => 'erika']);
        $this->connection->insert('tl_version', ['fromTable' => 'tl_member', 'pid' => 6, 'data' => 'fremd']);
    }

    public function testDerAntrag(): void
    {
        // Block G (G12): ihr Wunsch geht, der fremde bleibt.
        $this->connection->insert('tl_consho_stock_notice', ['article' => 40, 'member' => 5]);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 40, 'member' => 6]);

        $result = $this->erasure()->erase(1, 'm:5', strtotime('2026-09-28'));

        self::assertIsArray($result);
        self::assertSame(['WEB-1'], $result['anonymized']);
        self::assertSame([['number' => 'WEB-2', 'end' => mktime(23, 59, 59, 12, 31, 2031)]], $result['pending']);

        self::assertNotNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 1'));
        self::assertSame('', $this->connection->fetchOne('SELECT email FROM tl_consho_order WHERE id = 1'));

        // In der Frist: unverändert, aber vermerkt.
        self::assertSame(['Erika@Example.org', null], $this->connection->fetchNumeric('SELECT email, anonymizedAt FROM tl_consho_order WHERE id = 2') ?: []);
        self::assertSame(strtotime('2026-09-28'), (int) $this->connection->fetchOne('SELECT erasureRequestedAt FROM tl_consho_order WHERE id = 2'));

        // Kein Beleg: sofort.
        self::assertNull($this->connection->fetchOne('SELECT note FROM tl_consho_complaint WHERE pid = 2'));
        self::assertSame([12], array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_cart ORDER BY id')), 'Konto- und Gastkorb weg, der fremde bleibt');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
        self::assertSame([21], array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_opt_in')));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_opt_in_related'));
        self::assertSame([6], array_map('intval', $this->connection->fetchFirstColumn('SELECT member FROM tl_consho_stock_notice')));

        $member = $this->connection->fetchAssociative('SELECT * FROM tl_member WHERE id = 5');
        self::assertIsArray($member);
        foreach (CustomerErasure::MEMBER_FIELDS as $field) {
            self::assertSame('', $member[$field], $field);
        }
        self::assertNull($member['consho_confirmedEmailAt']);
        self::assertSame('erika@example.org', $member['email'], 'das Konto selbst löscht der Betreiber in Contao');
        self::assertSame(['tl_member:6'], array_map(static fn (array $r): string => $r['fromTable'].':'.$r['pid'], $this->connection->fetchAllAssociative('SELECT fromTable, pid FROM tl_version')), 'ihre Kontoversionen sind weg, fremde bleiben');

        // Das Fremde bleibt.
        self::assertSame('fremd@example.org', $this->connection->fetchOne('SELECT email FROM tl_consho_order WHERE id = 3'));
        self::assertSame('fremd', $this->connection->fetchOne('SELECT note FROM tl_consho_complaint WHERE pid = 3'));
    }

    /**
     * Abo-DSGVO, P87: ein laufendes Abo hält den Antrag nicht auf, es wird
     * genannt; ein beendetes geht mit seiner letzten Bestellung (P85).
     */
    public function testLaufendeAbosWerdenGenannt(): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => 40, 'subscriptionNumber' => 'WEB-1-1', 'member' => 5, 'firstOrder' => 1, 'state' => 'ended']);
        $this->connection->insert('tl_consho_subscription', ['id' => 41, 'subscriptionNumber' => 'WEB-2-1', 'member' => 5, 'firstOrder' => 2, 'state' => 'cancelled']);
        $this->connection->insert('tl_consho_subscription', ['id' => 42, 'subscriptionNumber' => 'WEB-3-1', 'member' => 6, 'firstOrder' => 3, 'state' => 'active']);

        $result = $this->erasure()->erase(1, 'm:5', strtotime('2026-09-28'));

        self::assertIsArray($result);
        self::assertSame(['WEB-1'], $result['anonymized'], 'der Antrag läuft trotzdem');
        self::assertSame(['WEB-2-1'], $result['running'], 'gekündigt ist nicht beendet; das fremde nicht');
        self::assertSame([0, 5, 6], array_map('intval', $this->connection->fetchFirstColumn('SELECT member FROM tl_consho_subscription ORDER BY id')));
    }

    /** Der Cron holt den Vermerk ein, auch ohne Automatik im Shop. */
    public function testDerCronHoltDenVermerkAmFristendeEin(): void
    {
        $this->erasure()->erase(1, 'm:5', strtotime('2026-09-28'));

        $this->cron(strtotime('2031-12-31 12:00'))();
        self::assertNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 2'), 'am letzten Tag der Frist noch nicht');

        $this->cron(strtotime('2032-01-01 00:00'))();
        self::assertNotNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 2'));
        self::assertNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 3'), 'ohne Antrag und ohne Automatik bleibt die fremde');
    }

    /**
     * Fable-Review Abo-DSGVO, S2: Das Abo der Gastbestellung 2 läuft noch,
     * als der Cron die Bestellung am Fristende anonymisiert. Endet es danach,
     * fragt keine Bestellung mehr — der tägliche Cron holt es ein, wie die
     * Meldung zum Löschantrag es verspricht.
     */
    public function testEinAboDasNachSeinerLetztenBestellungEndetHoltDerCronEin(): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => 41, 'subscriptionNumber' => 'WEB-2-1', 'member' => 5, 'firstOrder' => 2, 'state' => 'cancelled']);
        $this->connection->insert('tl_consho_cancellation', ['pid' => 41, 'number' => 'WEB-2-1-K1', 'name' => 'Erika Muster', 'email' => 'erika@example.org']);
        $this->connection->insert('tl_consho_subscription', ['id' => 42, 'subscriptionNumber' => 'WEB-3-1', 'member' => 6, 'firstOrder' => 3, 'state' => 'ended']);
        $this->erasure()->erase(1, 'm:5', strtotime('2026-09-28'));

        $this->cron(strtotime('2032-01-01 00:00'))();
        self::assertNotNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 2'));
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT member FROM tl_consho_subscription WHERE id = 41'), 'noch gekündigt, nicht beendet');

        $this->connection->update('tl_consho_subscription', ['state' => 'ended'], ['id' => 41]);
        $this->cron(strtotime('2032-01-02 00:00'))();

        self::assertSame([0, 6], array_map('intval', $this->connection->fetchFirstColumn('SELECT member FROM tl_consho_subscription ORDER BY id')), 'das fremde Abo hat eine Bestellung in der Frist');
        self::assertSame(['', ''], $this->connection->fetchNumeric('SELECT name, email FROM tl_consho_cancellation WHERE pid = 41') ?: []);
    }

    /** WA, A6: ohne Bestellung kein Beleg — der Cron leert sie auch ohne Automatik. */
    public function testDerCronLeertNichtZugeordneteWiderrufe(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 1, 'number' => 'W-00001', 'receivedAt' => strtotime('2023-03-01'), 'name' => 'Erika Muster', 'email' => 'erika@example.org']);

        $this->cron(strtotime('2027-01-01 00:00'))();

        self::assertSame(['', ''], $this->connection->fetchNumeric("SELECT name, email FROM tl_consho_withdrawal WHERE number = 'W-00001'") ?: []);
    }

    public function testMitAutomatikAuchOhneAntrag(): void
    {
        $this->connection->update('tl_consho_shop', ['autoAnonymize' => 1], ['id' => 1]);

        $this->cron(strtotime('2026-09-28'))();

        self::assertNotNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 1'));
        self::assertNotNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 3'));
        self::assertNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 2'));
    }

    public function testOhneFristNurVermerkt(): void
    {
        $this->connection->update('tl_consho_shop', ['retentionYears' => 0], ['id' => 1]);

        $result = $this->erasure()->erase(1, 'g:fremd@example.org', strtotime('2026-09-28'));

        self::assertSame(['anonymized' => [], 'pending' => [['number' => 'WEB-3', 'end' => null]], 'running' => []], $result);
        self::assertNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 3'));
    }

    /**
     * Abnahme D-5: Ein Fehler im letzten Schritt ließ die ersten geschrieben.
     * Jetzt: alles oder nichts — und keine Datei gelöscht.
     */
    public function testEinFehlerLaesstNichtsHalbErledigtZurueck(): void
    {
        $this->connection->insert('tl_consho_document', ['pid' => 1, 'documentNumber' => 'RE-1', 'archiveState' => 'stored', 'archivedPath' => 'rechnungen/RE-1.pdf']);
        $this->connection->executeStatement('DROP TABLE tl_opt_in_related');

        $deleted = [];
        $files = $this->createMock(VirtualFilesystemInterface::class);
        $files->method('fileExists')->willReturn(true);
        $files->method('delete')->willReturnCallback(static function (string $path) use (&$deleted): void {
            $deleted[] = $path;
        });

        try {
            (new CustomerErasure($this->connection, new CustomerDirectory($this->connection), new RetentionPolicy($this->connection), new OrderAnonymizer($this->connection, $files)))
                ->erase(1, 'm:5', strtotime('2026-09-28'));
            self::fail('Der Fehler muss durchschlagen.');
        } catch (\Throwable) {
        }

        self::assertNull($this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 1'));
        self::assertNull($this->connection->fetchOne('SELECT erasureRequestedAt FROM tl_consho_order WHERE id = 2'));
        self::assertSame('rechnungen/RE-1.pdf', $this->connection->fetchOne('SELECT archivedPath FROM tl_consho_document WHERE pid = 1'));
        self::assertSame([], $deleted, 'keine Datei weg, deren Zeile zurückgerollt wurde');
    }

    /** `_` und `%` in der Adresse sind im LIKE keine Platzhalter. */
    public function testPlatzhalterInDerAdresseTreffenNichtsFremdes(): void
    {
        $this->order(4, 0, 'a_b@example.org', '2024-01-01');
        $this->connection->insert('tl_consho_cart', ['id' => 13, 'member' => 0, 'checkoutData' => json_encode(['billing' => ['email' => 'axb@example.org']])]);
        $this->connection->insert('tl_consho_cart', ['id' => 14, 'member' => 0, 'checkoutData' => json_encode(['billing' => ['email' => 'a_b@example.org']])]);

        $this->erasure()->erase(1, 'g:a_b@example.org', strtotime('2026-09-28'));

        self::assertSame([10, 11, 12, 13], array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_cart ORDER BY id')));
    }

    public function testUnbekannterKunde(): void
    {
        self::assertNull($this->erasure()->erase(1, 'm:77', strtotime('2026-09-28')));
    }

    private function order(int $id, int $member, string $email, string $date): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => 1, 'member' => $member, 'orderNumber' => 'WEB-'.$id, 'email' => $email, 'firstname' => 'Erika', 'lastname' => 'Muster', 'createdAt' => strtotime($date)]);
    }

    private function anonymizer(): OrderAnonymizer
    {
        return new OrderAnonymizer($this->connection, $this->createStub(VirtualFilesystemInterface::class));
    }

    private function erasure(): CustomerErasure
    {
        return new CustomerErasure($this->connection, new CustomerDirectory($this->connection), new RetentionPolicy($this->connection), $this->anonymizer());
    }

    private function cron(int $now): RetentionCron
    {
        return new RetentionCron(new RetentionPolicy($this->connection), $this->anonymizer(), static fn (): int => $now);
    }
}

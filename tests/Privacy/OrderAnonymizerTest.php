<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Privacy;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Privacy\OrderAnonymizer;

/**
 * D-5, L4 — after the period: what names or reaches the person goes;
 * numbers, amounts, tax, items and dates stay.
 */
final class OrderAnonymizerTest extends TestCase
{
    private const NOW = 1_900_000_000;

    private Connection $connection;

    /** @var list<string> */
    private array $deleted = [];

    protected function setUp(): void
    {
        $this->connection = PrivacySchema::create();

        $person = [
            'salutation' => 'Frau', 'firstname' => 'Erika', 'lastname' => 'Muster', 'email' => 'erika@example.org', 'phone' => '+431234', 'company' => 'Muster GmbH',
            'billingStreet' => 'Hauptstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingState' => 'W', 'billingCountry' => 'AT',
            'shippingCompany' => 'Lager', 'shippingFirstname' => 'Max', 'shippingLastname' => 'Muster', 'shippingStreet' => 'Nebenweg 2', 'shippingPostal' => '1010', 'shippingCity' => 'Wien', 'shippingState' => 'W', 'shippingCountry' => 'AT', 'shippingPhone' => '+436641234567',
            'dateOfBirth' => '1980-01-01', 'vatId' => 'ATU12345678', 'vatIdRequestIdentifier' => 'WAPIAAAA',
        ];

        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 1, 'member' => 5, 'orderNumber' => 'WEB-7', 'grandTotal' => '22.8000', 'taxTotal' => '3.8000', 'createdAt' => 1_600_000_000] + $person);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'shop' => 1, 'member' => 5, 'orderNumber' => 'WEB-8', 'email' => 'erika@example.org']);
        $this->connection->insert('tl_consho_order_item', ['pid' => 7, 'productName' => 'Kräutersalz', 'lineTotal' => '22.8000']);

        $this->connection->insert('tl_consho_document', ['id' => 1, 'pid' => 7, 'documentNumber' => 'RE-1', 'archiveState' => 'stored', 'archivedPath' => 'rechnungen/RE-1.pdf', 'archivedHash' => 'abc',
            'payload' => json_encode(['formatVersion' => 3, 'order' => ['orderNumber' => 'WEB-7', 'grandTotal' => '22.8000'] + $person, 'items' => [['productName' => 'Kräutersalz']], 'issuer' => ['company' => 'Shop GmbH']])]);
        $this->connection->insert('tl_consho_document', ['id' => 2, 'pid' => 8, 'documentNumber' => 'RE-2', 'payload' => json_encode(['order' => ['email' => 'erika@example.org']])]);

        $this->connection->insert('tl_consho_withdrawal', ['id' => 3, 'pid' => 7, 'number' => 'WEB-7-W1', 'name' => 'Erika Muster', 'email' => 'erika@example.org', 'part' => 'das Salz', 'message' => 'Grüße, Erika', 'reason' => 'Frau Muster, zu spät', 'refundAmount' => '8.9']);
        $this->connection->insert('tl_consho_complaint', ['id' => 4, 'pid' => 7, 'note' => 'Frau Muster rief an']);
        $this->connection->insert('tl_consho_payment', ['id' => 5, 'pid' => 7, 'amount' => '22.8', 'reference' => 'Erika Muster WEB-7']);
        $this->connection->insert('tl_consho_delivery', ['id' => 6, 'pid' => 7, 'trackingNumber' => '1Z999']);
        $this->connection->insert('tl_consho_payment_attempt', ['pid' => 7, 'tokenHash' => 'a1', 'providerReference' => 'pi_ERIKA']);
        $this->connection->insert('tl_consho_payment_attempt', ['pid' => 7, 'tokenHash' => 'a2', 'providerReference' => 'pi_ERIKA2']);
        $this->connection->insert('tl_consho_cart_rule_redemption', ['id' => 1, 'rule' => 2, 'orderId' => 7, 'member' => 5, 'email' => 'erika@example.org', 'code' => 'Herbst']);
        $this->connection->insert('tl_consho_cart_rule_redemption', ['id' => 2, 'rule' => 2, 'orderId' => 8, 'member' => 5, 'email' => 'erika@example.org', 'code' => 'Herbst']);

        foreach ([['tl_consho_order', 7], ['tl_consho_order', 8], ['tl_consho_payment', 5], ['tl_consho_delivery', 6], ['tl_consho_complaint', 4], ['tl_consho_withdrawal', 3], ['tl_member', 7]] as [$table, $pid]) {
            $this->connection->insert('tl_version', ['fromTable' => $table, 'pid' => $pid, 'data' => 'a:1:{s:5:"email";s:17:"erika@example.org";}']);
        }

        $this->connection->insert('tl_consho_order_token', ['pid' => 7, 'tokenHash' => 'h7']);
        $this->connection->insert('tl_consho_order_token', ['pid' => 8, 'tokenHash' => 'h8']);
        // `order` is reserved, and Doctrine does not quote keys in insert().
        $this->connection->insert('tl_consho_cart', ['id' => 9, $this->connection->quoteIdentifier('order') => 7, 'checkoutData' => '{"billing":{"email":"erika@example.org"}}']);
        $this->connection->insert('tl_consho_cart_item', ['pid' => 9]);
    }

    public function testNachDerFristBleibtNurDasBuchhalterische(): void
    {
        self::assertTrue($this->anonymizer()->anonymize(7, self::NOW));

        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = 7');
        self::assertIsArray($order);

        foreach (OrderAnonymizer::ORDER_FIELDS as $field) {
            self::assertSame('', $order[$field], $field);
        }

        // Totals compared as numbers: decimal is NUMERIC in SQLite and comes
        // back as 22.8, where MariaDB returns '22.8000'.
        self::assertSame(['WEB-7', 22.8, 3.8, 'AT', 'AT', 0, self::NOW, 1_600_000_000], [$order['orderNumber'], (float) $order['grandTotal'], (float) $order['taxTotal'], $order['billingCountry'], $order['shippingCountry'], (int) $order['member'], (int) $order['anonymizedAt'], (int) $order['createdAt']]);
        self::assertSame('Kräutersalz', $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE pid = 7'));

        $payload = json_decode((string) $this->connection->fetchOne('SELECT payload FROM tl_consho_document WHERE id = 1'), true);
        self::assertIsArray($payload);
        self::assertSame('', $payload['order']['email']);
        self::assertSame('', $payload['order']['billingStreet']);
        self::assertSame('AT', $payload['order']['billingCountry']);
        self::assertSame('22.8000', $payload['order']['grandTotal']);
        self::assertSame('Shop GmbH', $payload['issuer']['company'], 'der Absender ist der Betreiber, nicht die Person');
        self::assertSame(3, $payload['formatVersion']);

        $document = $this->connection->fetchAssociative('SELECT archiveState, archivedPath, archivedHash FROM tl_consho_document WHERE id = 1');
        self::assertSame(['archiveState' => 'removed', 'archivedPath' => '', 'archivedHash' => ''], $document);
        self::assertSame(['rechnungen/RE-1.pdf'], $this->deleted);

        self::assertSame(['', '', null, null, null], $this->connection->fetchNumeric('SELECT name, email, part, message, reason FROM tl_consho_withdrawal WHERE id = 3') ?: []);
        self::assertEqualsWithDelta(8.9, (float) $this->connection->fetchOne('SELECT refundAmount FROM tl_consho_withdrawal WHERE id = 3'), 0.0001, 'der Betrag bleibt');
        self::assertNull($this->connection->fetchOne('SELECT note FROM tl_consho_complaint WHERE id = 4'));
        // The amount as a number: the DCA schema stores a decimal, SQLite
        // returns it as one; the old hand-written table had TEXT.
        $payment = $this->connection->fetchNumeric('SELECT reference, amount FROM tl_consho_payment WHERE id = 5') ?: [];
        self::assertSame('', $payment[0] ?? null);
        self::assertEqualsWithDelta(22.8, (float) ($payment[1] ?? 0), 0.0001, 'der Betrag bleibt');
        self::assertSame('', $this->connection->fetchOne('SELECT trackingNumber FROM tl_consho_delivery WHERE id = 6'));
        self::assertSame(['', ''], $this->connection->fetchFirstColumn('SELECT providerReference FROM tl_consho_payment_attempt WHERE pid = 7'));
        self::assertSame(['a1', 'a2'], $this->connection->fetchFirstColumn('SELECT tokenHash FROM tl_consho_payment_attempt WHERE pid = 7 ORDER BY id'), 'eindeutiger Hash ohne Personenbezug bleibt');
        // H-5: the redemption stays counted without saying by whom — that of the other order untouched.
        self::assertSame([['0', '', 'Herbst', '0'], ['5', 'erika@example.org', 'Herbst', '0']], array_map(static fn (array $r): array => array_map('strval', $r), $this->connection->fetchAllNumeric('SELECT member, email, code, cancelled FROM tl_consho_cart_rule_redemption ORDER BY id')));

        self::assertSame([['tl_consho_order', 8], ['tl_member', 7]], array_map(static fn (array $r): array => [$r['fromTable'], (int) $r['pid']], $this->connection->fetchAllAssociative('SELECT fromTable, pid FROM tl_version ORDER BY id')), 'die Versionen dieser Bestellung und ihrer Kinder sind weg — nicht die fremden');
        self::assertSame(['h8'], $this->connection->fetchFirstColumn('SELECT tokenHash FROM tl_consho_order_token'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));

        // The other order of the same person stays untouched.
        self::assertSame('erika@example.org', $this->connection->fetchOne('SELECT email FROM tl_consho_order WHERE id = 8'));
        self::assertStringContainsString('erika@example.org', (string) $this->connection->fetchOne('SELECT payload FROM tl_consho_document WHERE id = 2'));
    }

    /**
     * dev#2: The delivery note carries name, delivery address and tracking
     * number — all three go, the items stay.
     */
    public function testDerLieferscheinVerliertAdresseUndSendungsnummer(): void
    {
        $payload = (new DocumentPayloadBuilder())->deliveryNote(
            ['orderNumber' => 'WEB-7', 'firstname' => 'Erika', 'shippingStreet' => 'Nebenweg 2', 'shippingCountry' => 'AT'],
            [],
            [['productName' => 'Kräutersalz', 'sku' => 'KS', 'quantity' => '1.0000']],
            [],
            ['shippedAt' => '2026-10-21', 'carrier' => 'Post AT', 'trackingNumber' => '1Z999'],
        );
        $this->connection->insert('tl_consho_document', ['id' => 3, 'pid' => 7, 'type' => 'deliveryNote', 'documentNumber' => 'LS-1', 'payload' => json_encode($payload)]);

        $this->anonymizer()->anonymize(7, self::NOW);

        $anonymized = json_decode((string) $this->connection->fetchOne('SELECT payload FROM tl_consho_document WHERE id = 3'), true);
        self::assertIsArray($anonymized);
        self::assertSame(['', '', 'AT', 'WEB-7'], [$anonymized['order']['firstname'], $anonymized['order']['shippingStreet'], $anonymized['order']['shippingCountry'], $anonymized['order']['orderNumber']]);
        self::assertSame('', $anonymized['shipment']['trackingNumber']);
        self::assertSame('Post AT', $anonymized['shipment']['carrier']);
        self::assertSame('Kräutersalz', $anonymized['items'][0]['productName']);
    }

    public function testZweimalIstEinmal(): void
    {
        self::assertTrue($this->anonymizer()->anonymize(7, self::NOW));
        self::assertFalse($this->anonymizer()->anonymize(7, self::NOW + 1));
        self::assertSame(self::NOW, (int) $this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = 7'));
        self::assertFalse($this->anonymizer()->anonymize(99, self::NOW));
    }

    /**
     * Abo-DSGVO, P86 (K4 from F): the entitlement no longer names the account,
     * the download log goes, the key stays assigned.
     */
    public function testBerechtigungUndDownloadsDerBestellung(): void
    {
        $this->connection->insert('tl_consho_entitlement', ['id' => 30, 'pid' => 7, 'member' => 5, 'product' => 3]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 31, 'pid' => 8, 'member' => 5, 'product' => 3]);
        $this->connection->insert('tl_consho_download', ['pid' => 30, 'downloadedAt' => 1_600_000_100]);
        $this->connection->insert('tl_consho_download', ['pid' => 31, 'downloadedAt' => 1_600_000_200]);
        $this->connection->insert('tl_consho_key', ['pid' => 3, 'code' => 'ABCD-1234', 'entitlement' => 30]);
        $this->connection->insert('tl_version', ['fromTable' => 'tl_consho_entitlement', 'pid' => 30, 'data' => 'a:1:{s:6:"member";i:5;}']);

        self::assertTrue($this->anonymizer()->anonymize(7, self::NOW));

        self::assertSame([0, 5], array_map('intval', $this->connection->fetchFirstColumn('SELECT member FROM tl_consho_entitlement ORDER BY id')));
        self::assertSame([31], array_map('intval', $this->connection->fetchFirstColumn('SELECT pid FROM tl_consho_download')), 'nur das Protokoll dieser Bestellung');
        self::assertSame(['ABCD-1234', 30], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $this->connection->fetchNumeric('SELECT code, entitlement FROM tl_consho_key') ?: []));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_version WHERE fromTable = 'tl_consho_entitlement'"));
    }

    /**
     * Abo-DSGVO, P85: the subscription only goes once it has ended AND each of
     * its orders is anonymised — purchase (7) and follow-up order (8).
     */
    public function testAboErstNachSeinerLetztenBestellung(): void
    {
        $this->subscription(20, 'ended');
        $this->connection->update('tl_consho_order', ['subscription' => 20], ['id' => 8]);

        $this->anonymizer()->anonymize(7, self::NOW);

        self::assertSame(5, (int) $this->connection->fetchOne('SELECT member FROM tl_consho_subscription WHERE id = 20'), 'die Folgebestellung ist noch da');
        self::assertSame('cus_1|pm_2', $this->connection->fetchOne('SELECT mandate FROM tl_consho_subscription WHERE id = 20'));
        self::assertSame('erika@example.org', $this->connection->fetchOne('SELECT email FROM tl_consho_cancellation WHERE pid = 20'));

        $this->anonymizer()->anonymize(8, self::NOW);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT member FROM tl_consho_subscription WHERE id = 20'));
        self::assertSame(['', 0], array_map(static fn ($v) => \is_numeric($v) ? (int) $v : $v, $this->connection->fetchNumeric('SELECT mandate, mandateMethod FROM tl_consho_subscription WHERE id = 20') ?: []), 'das Mandat geht mit (P98)');
        self::assertSame(['', '', null, null, 'WEB-7-1-K1'], $this->connection->fetchNumeric('SELECT name, email, reason, rejectReason, number FROM tl_consho_cancellation WHERE pid = 20') ?: []);
        self::assertSame(['WEB-7-1', 'Monatsabo', 'Bedingungen'], $this->connection->fetchNumeric('SELECT subscriptionNumber, ruleTitle, terms FROM tl_consho_subscription WHERE id = 20') ?: [], 'Nummer und Bedingungen bleiben');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_version WHERE fromTable IN ('tl_consho_subscription', 'tl_consho_cancellation')"));
    }

    public function testLaufendesAboBleibt(): void
    {
        $this->subscription(20, 'cancelled');

        $this->anonymizer()->anonymize(7, self::NOW);

        self::assertSame(5, (int) $this->connection->fetchOne('SELECT member FROM tl_consho_subscription WHERE id = 20'), 'gekündigt, aber nicht beendet');
        self::assertSame('Erika Muster', $this->connection->fetchOne('SELECT name FROM tl_consho_cancellation WHERE pid = 20'));
    }

    /**
     * WA, A6: without an order no order period — three years from the end of
     * the year of receipt, then only what names nobody remains.
     */
    public function testNichtZugeordneterWiderrufNachDreiJahren(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 30, 'pid' => 0, 'shop' => 1, 'number' => 'W-00030', 'state' => 'received', 'receivedAt' => strtotime('2023-06-01 10:00'), 'name' => 'Erika Muster', 'email' => 'erika@example.org', 'orderReference' => 'WEB-77', 'part' => 'das Salz', 'message' => 'Grüße', 'reason' => 'nicht gefunden']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 31, 'pid' => 0, 'shop' => 1, 'number' => 'W-00031', 'state' => 'received', 'receivedAt' => strtotime('2024-01-02 10:00'), 'name' => 'Max', 'email' => 'max@example.org', 'orderReference' => 'WEB-78']);
        $this->connection->insert('tl_version', ['fromTable' => 'tl_consho_withdrawal', 'pid' => 30, 'data' => 'a:0:{}']);

        // 31.12.2026 23:59:59 is the end for 2023 — one second before, everything stays.
        self::assertSame(0, $this->anonymizer()->anonymizeUnassignedWithdrawals(strtotime('2026-12-31 23:59:59')));
        self::assertSame(1, $this->anonymizer()->anonymizeUnassignedWithdrawals(strtotime('2027-01-01 00:00:00')));

        self::assertSame(
            ['W-00030', 1, 'received', strtotime('2023-06-01 10:00'), '', '', '', null, null, null],
            array_values($this->connection->fetchAssociative('SELECT number, shop, state, receivedAt, name, email, orderReference, part, message, reason FROM tl_consho_withdrawal WHERE id = 30') ?: []),
        );
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_version WHERE fromTable = 'tl_consho_withdrawal' AND pid = 30"));
        self::assertSame('Max', $this->connection->fetchOne('SELECT name FROM tl_consho_withdrawal WHERE id = 31'), 'Eingang 2024: bis Ende 2027');
        self::assertSame('Erika Muster', $this->connection->fetchOne('SELECT name FROM tl_consho_withdrawal WHERE id = 3'), 'zugeordnet folgt der Bestellung');
        self::assertSame(0, $this->anonymizer()->anonymizeUnassignedWithdrawals(strtotime('2027-01-01 00:00:00')), 'zweimal ist einmal');
    }

    private function subscription(int $id, string $state): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => $id, 'subscriptionNumber' => 'WEB-7-1', 'member' => 5, 'firstOrder' => 7, 'ruleTitle' => 'Monatsabo', 'terms' => 'Bedingungen', 'state' => $state, 'mandate' => 'cus_1|pm_2', 'mandateMethod' => 8]);
        $this->connection->insert('tl_consho_cancellation', ['pid' => $id, 'number' => 'WEB-7-1-K1', 'name' => 'Erika Muster', 'email' => 'erika@example.org', 'reason' => 'Umzug', 'rejectReason' => 'Frau Muster, nein']);

        foreach ([['tl_consho_subscription', $id], ['tl_consho_cancellation', 1]] as [$table, $pid]) {
            $this->connection->insert('tl_version', ['fromTable' => $table, 'pid' => $pid, 'data' => 'a:1:{s:5:"email";s:17:"erika@example.org";}']);
        }
    }

    private function anonymizer(): OrderAnonymizer
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);
        $files->method('fileExists')->willReturn(true);
        $files->method('delete')->willReturnCallback(function (string $path): void {
            $this->deleted[] = $path;
        });

        return new OrderAnonymizer($this->connection, $files);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Privacy;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerDirectory;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Privacy\CustomerExport;

/**
 * D-5, L1 — the data access request as a ZIP: `daten.json` and the record
 * PDFs, everything about this person and nothing beyond.
 */
final class CustomerExportTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PrivacySchema::create();
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->executeStatement("ALTER TABLE tl_member ADD COLUMN password TEXT NOT NULL DEFAULT ''");
        $this->connection->insert('tl_member', ['id' => 5, 'firstname' => 'Erika', 'lastname' => 'Muster', 'email' => 'erika@example.org', 'password' => '$2y$geheim', 'consho_shippingStreet' => 'Lagerweg 3']);

        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 1, 'member' => 5, 'orderNumber' => 'WEB-7', 'email' => 'erika@example.org', 'firstname' => 'Erika', 'lastname' => 'Muster', 'phone' => '+43 1', 'billingStreet' => 'Hauptstraße 1', 'grandTotal' => '22.8000', 'createdAt' => strtotime('2026-09-20 10:00')]);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'shop' => 1, 'member' => 0, 'orderNumber' => 'WEB-8', 'email' => 'ERIKA@example.org', 'createdAt' => strtotime('2026-09-21')]);
        $this->connection->insert('tl_consho_order', ['id' => 9, 'shop' => 1, 'member' => 0, 'orderNumber' => 'WEB-9', 'email' => 'fremd@example.org', 'createdAt' => strtotime('2026-09-22')]);

        $this->connection->insert('tl_consho_order_item', ['pid' => 7, 'productName' => 'Kräutersalz', 'sku' => 'KS-200', 'lineTotal' => '22.8000']);
        $this->connection->insert('tl_consho_payment', ['pid' => 7, 'amount' => '22.8', 'method' => 'prepayment', 'reference' => 'WEB-7', 'receivedAt' => strtotime('2026-09-21')]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 7, 'number' => 'WEB-7-W1', 'name' => 'Erika Muster', 'email' => 'erika@example.org', 'message' => 'Passt nicht', 'receivedAt' => strtotime('2026-09-22')]);
        $this->connection->insert('tl_consho_complaint', ['pid' => 7, 'note' => 'Deckel kaputt']);
        $this->connection->insert('tl_consho_document', ['id' => 1, 'pid' => 7, 'type' => 'invoice', 'documentNumber' => 'RE-1', 'issuedAt' => strtotime('2026-09-20 10:01')]);
        $this->connection->insert('tl_consho_document', ['id' => 2, 'pid' => 9, 'type' => 'invoice', 'documentNumber' => 'RE-2']);

        $this->connection->insert('tl_consho_payment_attempt', ['pid' => 7, 'tokenHash' => 'x', 'methodType' => 'test', 'providerReference' => 'pi_1']);
        // Block H (H-5): one code for her order, one for someone else's.
        $this->connection->insert('tl_consho_cart_rule', ['id' => 2, 'title' => 'Herbstrabatt', 'code' => 'Herbst']);
        $this->connection->insert('tl_consho_cart_rule_redemption', ['rule' => 2, 'orderId' => 7, 'member' => 5, 'email' => 'erika@example.org', 'code' => 'Herbst', 'cancelled' => 1]);
        $this->connection->insert('tl_consho_cart_rule_redemption', ['rule' => 2, 'orderId' => 9, 'email' => 'fremd@example.org', 'code' => 'Herbst']);
        // WG-6: a purchased voucher (order 7) and one made by hand.
        $this->connection->insert('tl_consho_voucher', ['code' => 'AAAABBBBCCCCMDSN', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '25', 'balance' => '20', 'source' => 'purchase', 'orderId' => 7]);
        $this->connection->insert('tl_consho_voucher', ['code' => 'AAAABBBBCCCCZZZZ', 'shop' => 1, 'currency' => 'EUR', 'faceValue' => '10', 'balance' => '10', 'source' => 'manual', 'orderId' => 7]);
        $this->connection->insert('tl_consho_cart', ['id' => 30, 'member' => 5, 'checkoutData' => json_encode(['dateOfBirth' => '1980-01-01']), 'updatedAt' => 1]);
        $this->connection->insert('tl_opt_in', ['token' => 'cogo-z', 'email' => 'erika@example.org', 'createdOn' => 1]);
    }

    public function testAllesZuDieserPersonUndNichtsDarueberHinaus(): void
    {
        // Block G (G12): her wish to be notified — not someone else's.
        $this->connection->insert('tl_consho_product', ['id' => 40, 'title' => 'Wanderstock &amp; Hülle']);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 40, 'main' => 40, 'member' => 5, 'createdAt' => strtotime('2026-09-27 08:00')]);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 40, 'main' => 40, 'member' => 6, 'createdAt' => strtotime('2026-09-27 09:00')]);

        $export = $this->export()->export(1, 'm:5', strtotime('2026-09-28 12:00'));

        self::assertIsArray($export);
        self::assertSame('auskunft-2026-09-28.zip', $export['fileName']);

        $files = $this->unzip($export['zip']);
        self::assertSame(['daten.json', 'belege/RE-1.pdf'], array_keys($files), 'nur ihr Beleg, nicht der fremde');
        self::assertSame('%PDF-1', $files['belege/RE-1.pdf']);

        $data = json_decode($files['daten.json'], true);
        self::assertIsArray($data);
        self::assertSame(['kind' => 'member', 'name' => 'Erika Muster', 'email' => 'erika@example.org'], $data['customer']);
        self::assertSame('Lagerweg 3', $data['account']['consho_shippingStreet']);
        self::assertArrayNotHasKey('password', $data['account'], 'Zugangsdaten verlassen Contao nie');

        self::assertSame(['WEB-8', 'WEB-7'], array_column($data['orders'], 'orderNumber'), 'Konto und Gast mit ihrer Adresse');
        $order = $data['orders'][1];
        self::assertSame('Hauptstraße 1', $order['billingStreet']);
        self::assertSame('+43 1', $order['phone']);
        self::assertSame(date(\DATE_ATOM, strtotime('2026-09-20 10:00')), $order['createdAt']);
        self::assertArrayNotHasKey('id', $order);
        self::assertArrayNotHasKey('member', $order, 'interne IDs bleiben draußen');
        self::assertSame('Kräutersalz', $order['items'][0]['productName']);
        self::assertSame('WEB-7', $order['payments'][0]['reference']);
        self::assertSame('Passt nicht', $order['withdrawals'][0]['message']);
        self::assertSame('Deckel kaputt', $order['complaints'][0]['note']);
        self::assertSame(['type' => 'invoice', 'documentNumber' => 'RE-1', 'issuedAt' => date(\DATE_ATOM, strtotime('2026-09-20 10:01')), 'file' => 'belege/RE-1.pdf'], $order['documents'][0]);
        self::assertArrayNotHasKey('dateOfBirth', $order, 'leere Felder sind keine Auskunft');

        self::assertStringNotContainsString('fremd@example.org', $files['daten.json']);

        // D-5: payment attempts, open carts, open confirmations too.
        self::assertSame('pi_1', $data['orders'][1]['paymentAttempts'][0]['providerReference']);
        self::assertSame([['code' => 'Herbst', 'title' => 'Herbstrabatt', 'cancelled' => 1]], array_map(static fn (array $row): array => ['code' => $row['code'], 'title' => $row['title'], 'cancelled' => (int) $row['cancelled']], $data['orders'][1]['discountCodes']));
        self::assertSame([], $data['orders'][0]['discountCodes']);

        // WG-6: the purchased vouchers, only with the last group — the
        // code is money, and the data export is a file that gets passed on.
        $vouchers = $data['orders'][1]['vouchers'];
        self::assertCount(1, $vouchers);
        self::assertSame('…MDSN', $vouchers[0]['code']);
        self::assertEquals(20, $vouchers[0]['balance']);
        self::assertStringNotContainsString('AAAABBBBCCCCMDSN', $files['daten.json']);
        self::assertSame('1980-01-01', $data['baskets'][0]['checkout']['dateOfBirth']);
        self::assertSame('erika@example.org', $data['pendingConfirmations'][0]['email']);
        self::assertSame([['product' => 'Wanderstock & Hülle', 'createdAt' => date(\DATE_ATOM, strtotime('2026-09-27 08:00'))]], $data['stockNotices']);
    }

    /**
     * Abo-DSGVO, P84 (K4 from F): entitlements with downloads and keys
     * in plain text, the subscriptions with their cancellations — not
     * other people's.
     */
    public function testAbosBerechtigungenDownloadsSchluessel(): void
    {
        $item = (int) $this->connection->fetchOne('SELECT id FROM tl_consho_order_item WHERE pid = 7');
        $this->connection->insert('tl_consho_entitlement', ['id' => 40, 'pid' => 7, 'orderItem' => $item, 'member' => 5, 'deliveryType' => 'key', 'validFrom' => strtotime('2026-09-20 10:05'), 'validUntil' => null]);
        $this->connection->insert('tl_consho_download', ['pid' => 40, 'downloadedAt' => strtotime('2026-09-21 08:00')]);
        $this->connection->insert('tl_consho_key', ['pid' => 3, 'code' => 'ABCD-1234', 'entitlement' => 40, 'assignedAt' => strtotime('2026-09-20 10:05')]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 41, 'pid' => 9, 'member' => 0]);
        $this->connection->insert('tl_consho_key', ['pid' => 3, 'code' => 'FREMD-9999', 'entitlement' => 41]);

        $this->connection->insert('tl_consho_payment_method', ['id' => 8, 'type' => 'card', 'title' => 'Kreditkarte']);
        $this->connection->insert('tl_consho_subscription', ['id' => 50, 'subscriptionNumber' => 'WEB-7-1', 'member' => 5, 'firstOrder' => 7, 'ruleTitle' => 'Monatsabo &#40;Kurs&#41;', 'terms' => '1,20 € / Monat', 'state' => 'cancelled', 'endsAt' => strtotime('2026-10-31'), 'reminderSentAt' => 0, 'mandate' => 'cus_GEHEIM|pm_GEHEIM', 'mandateMethod' => 8]);
        $this->connection->insert('tl_consho_cancellation', ['pid' => 50, 'number' => 'WEB-7-1-K1', 'name' => 'Erika Muster', 'email' => 'erika@example.org', 'reason' => 'Umzug']);
        $this->connection->insert('tl_consho_subscription_pause', ['pid' => 50, 'months' => 2, 'pauseFrom' => strtotime('2026-10-01'), 'pauseUntil' => strtotime('2026-12-01'), 'source' => 'account', 'requestedAt' => strtotime('2026-09-15')]);
        $this->connection->insert('tl_consho_subscription', ['id' => 51, 'subscriptionNumber' => 'WEB-9-1', 'member' => 0, 'firstOrder' => 9]);

        $files = $this->unzip($this->export()->export(1, 'm:5', strtotime('2026-09-28 12:00'))['zip'] ?? '');
        $data = json_decode($files['daten.json'], true);
        self::assertIsArray($data);

        $entitlement = $data['orders'][1]['entitlements'][0];
        self::assertSame('Kräutersalz', $entitlement['productName']);
        self::assertSame(date(\DATE_ATOM, strtotime('2026-09-20 10:05')), $entitlement['validFrom']);
        self::assertSame([date(\DATE_ATOM, strtotime('2026-09-21 08:00'))], $entitlement['downloads']);
        self::assertSame([['code' => 'ABCD-1234', 'assignedAt' => date(\DATE_ATOM, strtotime('2026-09-20 10:05'))]], $entitlement['keys']);

        self::assertCount(1, $data['subscriptions']);
        $subscription = $data['subscriptions'][0];
        // Abo-DSGVO: Contao's input encoding does not belong in the copy.
        self::assertSame(['WEB-7-1', 'WEB-7', 'Monatsabo (Kurs)', '1,20 € / Monat', 'cancelled'], [$subscription['subscriptionNumber'], $subscription['purchaseOrder'], $subscription['ruleTitle'], $subscription['terms'], $subscription['state']]);
        self::assertArrayNotHasKey('reminderSentAt', $subscription, '0 heißt nie — kein Zeitpunkt, keine Auskunft');
        self::assertSame(date(\DATE_ATOM, strtotime('2026-10-31')), $subscription['endsAt']);
        self::assertSame('Umzug', $subscription['cancellations'][0]['reason']);
        self::assertArrayNotHasKey('member', $subscription, 'interne IDs bleiben draußen');

        // Pause a subscription (P111): the subscription's pauses.
        self::assertSame([['months' => 2, 'pauseFrom' => date(\DATE_ATOM, strtotime('2026-10-01')), 'pauseUntil' => date(\DATE_ATOM, strtotime('2026-12-01')), 'source' => 'account', 'requestedAt' => date(\DATE_ATOM, strtotime('2026-09-15'))]], $subscription['pauses']);

        // P98: only the payment method, never the value of the mandate.
        self::assertSame('Kreditkarte', $subscription['followUpPayment']);
        self::assertArrayNotHasKey('mandate', $subscription);
        self::assertArrayNotHasKey('mandateMethod', $subscription);
        self::assertStringNotContainsString('GEHEIM', implode('', $files));

        self::assertStringNotContainsString('FREMD-9999', $files['daten.json']);
        self::assertStringNotContainsString('WEB-9-1', $files['daten.json']);
    }

    /**
     * WA, A6: declarations via the form without a matching order — via
     * the address entered, only in one's own shop.
     */
    public function testNichtZugeordneteWiderrufeUeberDieAdresse(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 1, 'number' => 'W-00011', 'source' => 'form', 'name' => 'Erika Muster', 'email' => 'Erika@Example.org', 'orderReference' => 'WEB-77', 'message' => 'Bitte zurück', 'receivedAt' => strtotime('2026-09-23 09:00')]);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 2, 'number' => 'W-00012', 'name' => 'Erika Muster', 'email' => 'erika@example.org', 'orderReference' => 'ANDERER-SHOP']);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 0, 'shop' => 1, 'number' => 'W-00013', 'name' => 'Fremd', 'email' => 'fremd@example.org', 'orderReference' => 'FREMD-1']);

        $data = json_decode($this->unzip($this->export()->export(1, 'm:5', strtotime('2026-09-28 12:00'))['zip'] ?? '')['daten.json'], true);

        self::assertIsArray($data);
        self::assertSame([[
            'number' => 'W-00011', 'receivedAt' => date(\DATE_ATOM, strtotime('2026-09-23 09:00')), 'source' => 'form', 'name' => 'Erika Muster', 'email' => 'Erika@Example.org',
            'orderReference' => 'WEB-77', 'scope' => 'all', 'message' => 'Bitte zurück', 'state' => 'received',
        ]], $data['unassignedWithdrawals']);
        self::assertStringNotContainsString('ANDERER-SHOP', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('FREMD-1', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testEinGastOhneKonto(): void
    {
        $data = json_decode($this->unzip($this->export()->export(1, 'g:fremd@example.org', 1)['zip'] ?? '')['daten.json'], true);

        self::assertIsArray($data);
        self::assertSame('guest', $data['customer']['kind']);
        self::assertNull($data['account']);
        self::assertSame(['WEB-9'], array_column($data['orders'], 'orderNumber'));
    }

    public function testUnbekannt(): void
    {
        self::assertNull($this->export()->export(1, 'm:77', 1));
    }

    public function testRechnungsdatenOhneConshoBelegNennenDenAussteller(): void
    {
        // Block R (R16): the row of an invoice made elsewhere has no number of Consho's.
        $this->connection->update('tl_consho_document', ['documentNumber' => null, 'issuer' => 'off'], ['id' => 1]);

        $export = $this->export()->export(1, 'm:5', strtotime('2026-09-28 12:00'));
        self::assertIsArray($export);
        $data = json_decode($this->unzip($export['zip'])['daten.json'], true);
        self::assertIsArray($data);

        $document = $data['orders'][1]['documents'][0];
        self::assertSame('off', $document['issuer']);
        self::assertArrayNotHasKey('documentNumber', $document);
    }

    private function export(): CustomerExport
    {
        $pdfs = new class implements DocumentPdfInterface {
            public function sentElsewhere(int $documentId): bool
            {
                return false;
            }

            /**
             * @return array{pdf: string, fileName: string, fromArchive: bool, archiveProblem: bool, logoMissing: bool}
             */
            public function pdf(int $documentId): array
            {
                return ['pdf' => '%PDF-'.$documentId, 'fileName' => 'RE-'.$documentId.'.pdf', 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
            }
        };

        return new CustomerExport($this->connection, new CustomerDirectory($this->connection), $pdfs);
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $zip): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'consho-test');
        file_put_contents($path, $zip);
        $archive = new \ZipArchive();
        $archive->open($path);
        $files = [];

        for ($i = 0; $i < $archive->numFiles; ++$i) {
            $name = (string) $archive->getNameIndex($i);
            $files[$name] = (string) $archive->getFromName($name);
        }

        $archive->close();
        unlink($path);

        return $files;
    }
}

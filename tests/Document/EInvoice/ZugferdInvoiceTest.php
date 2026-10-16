<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\EInvoice;

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\EInvoice\ZugferdInvoice;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\DompdfRenderer;
use Psr\Log\LoggerInterface;

/**
 * Block R (R9–R12): das ZUGFeRD-XML aus dem Schnappschuss — gegen das Schema
 * geprüft (im Erzeuger) und hier zurückgelesen.
 */
class ZugferdInvoiceTest extends TestCase
{
    public function testDieRechnungAnEinenGeschaeftskunden(): void
    {
        $payload = $this->payload([
            $this->line('Tee', '2.0000', '50.0000', '19.0000', 'DE'),
            $this->line('Paket', '1.0000', '5.0000', '19.0000', 'DE', lineType: 'shipping'),
            // Ein Nachlass: negativer Betrag, also negative Menge (BR-27).
            $this->line('Vorkasse-Nachlass', '1.0000', '-2.0000', '19.0000', 'DE', lineType: 'paymentDiscount'),
        ]);

        $xml = (new ZugferdInvoice())->xml($this->document('invoice'), $payload);
        self::assertNotNull($xml);
        $x = $this->xpath($xml);

        self::assertSame('380', $x->evaluate('string(//rsm:ExchangedDocument/ram:TypeCode)'));
        self::assertSame('RE-00099', $x->evaluate('string(//rsm:ExchangedDocument/ram:ID)'));
        self::assertSame('urn:cen.eu:en16931:2017', $x->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'));
        self::assertSame('ATU12345678', $x->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID)'));
        self::assertSame('rechnung@example.org', $x->evaluate('string(//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID)'));
        self::assertSame('Muster Handel GmbH', $x->evaluate('string(//ram:BuyerTradeParty/ram:Name)'));
        self::assertSame('DE123456789', $x->evaluate('string(//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID)'));
        self::assertSame(['2', '1', '-1'], $this->all($x, '//ram:IncludedSupplyChainTradeLineItem//ram:BilledQuantity'));
        self::assertSame(['50.00', '5.00', '2.00'], $this->all($x, '//ram:NetPriceProductTradePrice/ram:ChargeAmount', 2));
        self::assertSame(['S'], $this->all($x, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'));
        // 100 + 5 − 2 = 103 netto, 19 % = 19,57 — die Summe, die das PDF druckt.
        self::assertSame('103.00', $x->evaluate('string(//ram:TaxBasisTotalAmount)'));
        self::assertSame('19.57', $x->evaluate('string(//ram:TaxTotalAmount)'));
        self::assertSame('122.57', $x->evaluate('string(//ram:GrandTotalAmount)'));
        self::assertSame('58', $x->evaluate('string(//ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode)'));
        self::assertSame('AT611904300234573201', $x->evaluate('string(//ram:PayeePartyCreditorFinancialAccount/ram:IBANID)'));
        self::assertSame('20261021', $x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString)'));
    }

    /**
     * Storno und Korrektur sind Gutschriften (381) mit positiven Beträgen und
     * dem Verweis auf ihre Rechnung.
     */
    public function testDerStornoIstEineGutschriftMitVerweis(): void
    {
        $builder = new DocumentPayloadBuilder();
        $invoice = $this->payload([$this->line('Tee', '1.0000', '50.0000', '19.0000', 'DE')]);
        $payload = $builder->cancellationOf($invoice, 'RE-00099', (int) strtotime('2026-10-07 12:00'));

        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('cancellation', 'ST-00001'), $payload));

        self::assertSame('381', $x->evaluate('string(//rsm:ExchangedDocument/ram:TypeCode)'));
        self::assertSame('RE-00099', $x->evaluate('string(//ram:InvoiceReferencedDocument/ram:IssuerAssignedID)'));
        self::assertSame('59.50', $x->evaluate('string(//ram:GrandTotalAmount)'));
        self::assertSame(['1'], $this->all($x, '//ram:BilledQuantity'));
        // Zweites Review R, B2 (BR-CO-25): auch die Gutschrift nennt, was mit dem Betrag geschieht.
        self::assertSame('Refund', $x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:Description)'));
    }

    /**
     * Reverse Charge: Ware ist K (innergemeinschaftliche Lieferung) samt
     * Befreiungsgrund, eine Dienstleistung AE.
     */
    public function testReverseChargeIstKFuerWareUndAeSonst(): void
    {
        self::assertSame('K', ZugferdInvoice::category(['priceSource' => 'reverseCharge', 'kind' => 'physical', 'lineType' => 'product', 'taxRate' => '0'], 'DE', true));
        self::assertSame('AE', ZugferdInvoice::category(['priceSource' => 'reverseCharge', 'kind' => 'digital', 'lineType' => 'product', 'taxRate' => '0'], 'DE', false));
        self::assertSame('K', ZugferdInvoice::category(['priceSource' => 'reverseCharge', 'lineType' => 'shipping', 'taxRate' => '0'], 'DE', true));
        self::assertSame('G', ZugferdInvoice::category(['priceSource' => 'list', 'taxRate' => '0.0000'], 'CH', false));
        self::assertSame('Z', ZugferdInvoice::category(['priceSource' => 'list', 'taxRate' => '0.0000'], 'AT', false));
        self::assertSame('O', ZugferdInvoice::category(['priceSource' => 'notTaxable', 'taxRate' => '0'], 'DE', false));

        $payload = $this->payload([$this->line('Tee', '1.0000', '50.0000', '0.0000', 'DE', source: 'reverseCharge')]);
        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));

        self::assertSame('K', $x->evaluate('string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode)'));
        self::assertSame('VATEX-EU-IC', $x->evaluate('string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode)'));
        self::assertSame('DE', $x->evaluate('string(//ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID)'));
    }

    public function testKeinXmlAnVerbraucherUndKeinesFuerNichtSteuerbarNebenSteuerbarem(): void
    {
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message) use (&$messages): void {
            $messages[] = (string) $message;
        });
        $invoice = new ZugferdInvoice($logger);

        $consumer = $this->payload([$this->line('Tee', '1.0000', '50.0000', '19.0000', 'DE')]);
        $consumer['eInvoice'] = false;
        self::assertNull($invoice->xml($this->document('invoice'), $consumer));

        $mixed = $this->payload([
            $this->line('Tee', '1.0000', '50.0000', '19.0000', 'DE'),
            $this->line('Gutschein', '1.0000', '25.0000', '0.0000', '', source: 'notTaxable'),
        ]);
        self::assertNull($invoice->xml($this->document('invoice'), $mixed));
        self::assertStringContainsString('BR-O-11', implode("\n", $messages));
    }

    /**
     * Mit dompdf und eingebettet: das PDF trägt genau dieses XML.
     */
    public function testDasPdfTraegtDasXml(): void
    {
        $payload = $this->payload([$this->line('Tee', '1.0000', '50.0000', '19.0000', 'DE')]);
        $document = $this->document('invoice');
        $pdf = (new DompdfRenderer(sys_get_temp_dir().'/consho-dompdf-test'))->render('<html><body><p>RE-00099</p></body></html>');

        $invoice = new ZugferdInvoice();
        $merged = $invoice->embed($pdf, $document, $payload);

        self::assertNotSame($pdf, $merged);
        self::assertSame($invoice->xml($document, $payload), ZugferdDocumentPdfReader::getXmlFromContent($merged));

        $payload['eInvoice'] = false;
        self::assertSame($pdf, $invoice->embed($pdf, $document, $payload), 'ohne E-Rechnung bleibt das PDF, wie es war');
    }

    /**
     * Review R, F3: die Summen des XML sind die des PDF — gerechnet wie
     * `summariseTax` (gerundete Zeilen, Steuer = Brutto − Netto), geprüft
     * gegen die Steuerübersicht des Schnappschusses, nicht gegen eine eigene
     * Rechnung (Regel 24). Vorher: 3 × 8,3417 zu 20 % ergab im XML 30,04
     * und 5,01, gedruckt 30,03 und 5,00.
     */
    public function testDieSummenSindDieDesPdf(): void
    {
        foreach ([
            [$this->line('Bruttopreis 10,01', '3.0000', '8.3417', '20.0000', 'DE')],
            [$this->line('A', '1.0000', '10.0050', '19.0000', 'DE'), $this->line('B', '1.0000', '10.0050', '19.0000', 'DE'), $this->line('C', '1.0000', '10.0050', '19.0000', 'DE')],
        ] as $items) {
            $payload = $this->payload($items);
            $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));

            $net = '0';
            $tax = '0';
            $gross = '0';

            foreach ($payload['taxSummary'] as $entry) {
                $net = bcadd($net, $entry['net'], 2);
                $tax = bcadd($tax, $entry['tax'], 2);
                $gross = bcadd($gross, $entry['gross'], 2);
            }

            self::assertSame($net, $x->evaluate('string(//ram:TaxBasisTotalAmount)'));
            self::assertSame($tax, $x->evaluate('string(//ram:TaxTotalAmount)'));
            self::assertSame($gross, $x->evaluate('string(//ram:GrandTotalAmount)'));

            // BR-CO-10: Kopf = Summe der Zeilen.
            $lines = '0';

            foreach ($this->all($x, '//ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', 2) as $amount) {
                $lines = bcadd($lines, $amount, 2);
            }

            self::assertSame($lines, $x->evaluate('string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount)'));
        }
    }

    /**
     * Review R, F4 (BR-CO-25): Vorkasse nennt kein Datum — dann die
     * Zahlungsbedingung, der Titel der Zahlungsart, wie die Bestellung ihn
     * eingefroren hat. Nichts offen: keine Bedingung nötig.
     */
    public function testVorkasseNenntIhreZahlungsbedingung(): void
    {
        $payload = $this->payload([$this->line('Tee', '1.0000', '50.0000', '19.0000', 'DE')]);
        unset($payload['payment']['dueDate']);
        $payload['order']['paymentMethodTitle'] = 'Vorkasse';

        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));
        self::assertSame('Vorkasse', $x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:Description)'));

        $payload['payment']['payments'] = [['method' => 'Gutschein …MDSN', 'amount' => '59.5000']];
        $paid = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));
        self::assertSame(0.0, $paid->evaluate('count(//ram:SpecifiedTradePaymentTerms)'));
        self::assertSame('0.00', $paid->evaluate('string(//ram:DuePayableAmount)'));
    }

    /**
     * Review R, F5 (BR-O-2): nur „nicht steuerbar" — ohne UIDs.
     */
    public function testNurNichtSteuerbarOhneUid(): void
    {
        $payload = $this->payload([$this->line('Gutschein', '1.0000', '25.0000', '0.0000', '', source: 'notTaxable')]);
        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));

        self::assertSame(['O'], $this->all($x, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'));
        self::assertSame(0.0, $x->evaluate('count(//ram:SpecifiedTaxRegistration)'));
    }

    /**
     * Review R, F7 (BR-IC-12): ohne Lieferanschrift ging die Ware an die
     * Rechnungsanschrift — das Lieferland steht trotzdem.
     */
    public function testOhneLieferanschriftDieRechnungsanschrift(): void
    {
        $payload = $this->payload([$this->line('Tee', '1.0000', '50.0000', '0.0000', 'DE', source: 'reverseCharge')]);
        $payload['order']['shippingCountry'] = '';

        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));

        self::assertSame('DE', $x->evaluate('string(//ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID)'));
        self::assertSame('Muster Handel GmbH', $x->evaluate('string(//ram:ShipToTradeParty/ram:Name)'));
    }

    /**
     * Review R, F9 (Regel 43): je Ausgang ohne XML ein Zustand, in dem nur
     * er greift.
     */
    public function testJederAusgangOhneXmlGreiftFuerSich(): void
    {
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message) use (&$messages): void {
            $messages[] = (string) $message;
        });
        $invoice = new ZugferdInvoice($logger);
        $payload = $this->payload([$this->line('Tee', '1.0000', '50.0000', '19.0000', 'DE')]);

        // Ein Lieferschein ist keine Rechnung — kein XML, kein Log.
        self::assertNull($invoice->xml($this->document('deliveryNote'), $payload));
        self::assertSame([], $messages);

        // Der Absender ohne UID — nach dem Einschalten geleert.
        $withoutVatId = $payload;
        $withoutVatId['issuer']['vatId'] = '';
        self::assertNull($invoice->xml($this->document('invoice'), $withoutVatId));
        self::assertStringContainsString('company, country or VAT ID', implode("\n", $messages));

        // Gegenprobe: derselbe Schnappschuss mit UID ergibt XML.
        self::assertNotNull($invoice->xml($this->document('invoice'), $payload));
    }
    /**
     * Zweites Review R, B3: eine Währung ohne Nachkommastellen rechnet wie
     * das PDF (auf ihre Stellen), eine mit drei bekommt kein XML.
     */
    public function testDieStellenDerWaehrung(): void
    {
        $payload = $this->payload([$this->line('Tee', '3.0000', '8.3417', '10.0000', 'DE')]);
        $payload['order']['currency'] = 'JPY';
        $payload['taxSummary'] = (new DocumentPayloadBuilder())->summariseTax($payload['items'], 'JPY');

        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));
        $entry = array_values($payload['taxSummary'])[0];

        self::assertSame([(float) $entry['net'], (float) $entry['tax'], (float) $entry['gross']], [
            (float) $x->evaluate('string(//ram:TaxBasisTotalAmount)'),
            (float) $x->evaluate('string(//ram:TaxTotalAmount)'),
            (float) $x->evaluate('string(//ram:GrandTotalAmount)'),
        ]);

        $payload['order']['currency'] = 'BHD';
        self::assertNull((new ZugferdInvoice())->xml($this->document('invoice'), $payload));
    }

    /**
     * Zweites Review R, B6: ohne Titel der Zahlungsart die Bedingung in der
     * Sprache der Bestellung.
     */
    public function testDieRueckfallbedingungInDerSpracheDerBestellung(): void
    {
        $payload = $this->payload([$this->line('Tee', '1.0000', '50.0000', '19.0000', 'DE')]);
        unset($payload['payment']['dueDate']);
        $payload['order']['paymentMethodTitle'] = '';
        $payload['order']['language'] = 'de';

        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));
        self::assertSame('Vorkasse', $x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:Description)'));
    }
    /**
     * @param list<array<string, string>> $items
     *
     * @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        $net = '0';
        $tax = '0';

        foreach ($items as $item) {
            $net = bcadd($net, bcsub($item['lineTotal'], $item['taxAmount'], 4), 4);
            $tax = bcadd($tax, $item['taxAmount'], 4);
        }

        $payload = (new DocumentPayloadBuilder())->build(
            [
                'orderNumber' => 'WEB-00099', 'currency' => 'EUR', 'firstname' => 'Eva', 'lastname' => 'Muster', 'company' => 'Muster Handel GmbH',
                'email' => 'eva@example.org', 'vatId' => 'DE123456789', 'billingStreet' => 'Lagerweg 9', 'billingPostal' => '10115',
                'billingCity' => 'Berlin', 'billingCountry' => 'DE', 'shippingCountry' => 'DE', 'shippingStreet' => 'Lagerweg 9',
                'shippingPostal' => '10115', 'shippingCity' => 'Berlin',
                'netTotal' => bcadd($net, '0', 2), 'taxTotal' => bcadd($tax, '0', 2), 'grandTotal' => bcadd(bcadd($net, $tax, 4), '0', 2),
                'businessCustomer' => '1',
            ],
            $items,
            [
                'issuer' => ['company' => 'Muster', 'legalForm' => 'GmbH', 'street' => 'Musterstraße 1', 'postal' => '1100', 'city' => 'Wien', 'country' => 'AT', 'vatId' => 'ATU12345678', 'email' => 'rechnung@example.org'],
                'payment' => ['instructions' => '', 'details' => ['accountHolder' => 'Muster GmbH', 'iban' => 'AT61 1904 3002 3457 3201', 'bic' => 'BKAUATWW'], 'meansCode' => '58'],
                'delivery' => ['from' => '2026-10-07', 'to' => '2026-10-07', 'kind' => 'delivered'],
                'eInvoice' => true,
            ],
        );
        $payload['payment']['dueDate'] = '2026-10-21';

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function line(string $name, string $quantity, string $unitPrice, string $rate, string $country, string $lineType = 'product', string $source = 'list'): array
    {
        $net = bcmul($quantity, $unitPrice, 4);
        $tax = bcdiv(bcmul($net, $rate, 6), '100', 4);

        return [
            'productName' => $name, 'sku' => '', 'quantity' => $quantity, 'unitPrice' => $unitPrice, 'taxRate' => $rate,
            'taxAmount' => $tax, 'lineTotal' => bcadd($net, $tax, 4), 'priceSource' => $source, 'lineType' => $lineType,
            'taxCountry' => $country, 'kind' => 'product' === $lineType ? 'physical' : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(string $type, string $number = 'RE-00099'): array
    {
        return ['type' => $type, 'documentNumber' => $number, 'issuedAt' => (int) strtotime('2026-10-07 12:00')];
    }

    private function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        return $xpath;
    }

    /**
     * @return list<string>
     */
    private function all(\DOMXPath $xpath, string $query, int|null $places = null): array
    {
        $values = [];

        foreach ($xpath->query($query) ?: [] as $node) {
            $value = trim((string) $node->textContent);
            $values[] = null === $places ? rtrim(rtrim(str_contains($value, '.') ? $value : $value.'.', '0'), '.') : number_format((float) $value, $places, '.', '');
        }

        return $values;
    }
}

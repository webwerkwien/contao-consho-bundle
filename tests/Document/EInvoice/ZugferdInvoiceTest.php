<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\EInvoice;

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\EInvoice\ZugferdInvoice;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\DompdfRenderer;
use Webwerkwien\ContaoConshoBundle\Voucher\SinglePurpose;
use Psr\Log\LoggerInterface;

/**
 * Block R (R9–R12): the ZUGFeRD XML from the snapshot — validated against
 * the schema (in the generator) and read back here.
 */
class ZugferdInvoiceTest extends TestCase
{
    public function testDieRechnungAnEinenGeschaeftskunden(): void
    {
        $payload = $this->payload([
            $this->line('Tee', '2.0000', '50.0000', '19.0000', 'DE'),
            $this->line('Paket', '1.0000', '5.0000', '19.0000', 'DE', lineType: 'shipping'),
            // A discount: negative amount, hence negative quantity (BR-27).
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
        // 100 + 5 − 2 = 103 net, 19 % = 19.57 — the total the PDF prints.
        self::assertSame('103.00', $x->evaluate('string(//ram:TaxBasisTotalAmount)'));
        self::assertSame('19.57', $x->evaluate('string(//ram:TaxTotalAmount)'));
        self::assertSame('122.57', $x->evaluate('string(//ram:GrandTotalAmount)'));
        self::assertSame('58', $x->evaluate('string(//ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode)'));
        self::assertSame('AT611904300234573201', $x->evaluate('string(//ram:PayeePartyCreditorFinancialAccount/ram:IBANID)'));
        self::assertSame('20261021', $x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString)'));
    }

    /**
     * Cancellation and correction are credit notes (381) with positive amounts
     * and the reference to their invoice.
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
        // R (BR-CO-25): the credit note, too, says what happens with the amount.
        self::assertSame('Refund', $x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:Description)'));
    }

    /**
     * EZ-4: the cancellation reverses the deduction with the lines — as a
     * credit note it carries the same allowance, positive. A correction
     * carries only what its refund booked back onto single-purpose vouchers,
     * not the invoice's deduction.
     */
    public function testStornoUndKorrekturNehmenDenAbzugMit(): void
    {
        $builder = new DocumentPayloadBuilder();
        $invoice = $this->payload([$this->line('Tee', '2.0000', '25.0000', '20.0000', 'AT')]);
        $invoice['prepaid'] = [['label' => 'Gutschein …MDSN', 'country' => 'AT', 'rate' => '20.0000', 'net' => '20.0000', 'tax' => '4.0000', 'gross' => '24.0000']];

        $cancellation = $builder->cancellationOf($invoice, 'RE-00099', (int) strtotime('2026-10-07 12:00'));
        self::assertSame(['-20.0000', '-4.0000', '-24.0000'], [$cancellation['prepaid'][0]['net'], $cancellation['prepaid'][0]['tax'], $cancellation['prepaid'][0]['gross']]);

        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('cancellation', 'ST-00001'), $cancellation));
        self::assertSame(['20.00'], $this->all($x, '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount', 2));
        self::assertSame('36.00', $x->evaluate('string(//ram:GrandTotalAmount)'));

        $line = $this->line('Tee', '1.0000', '-25.0000', '20.0000', 'AT');
        $back = [['label' => 'Gutschein …MDSN', 'country' => 'AT', 'rate' => '20.0000', 'net' => '-10.0000', 'tax' => '-2.0000', 'gross' => '-12.0000']];
        self::assertSame([], $builder->correctionOf($invoice, 'RE-00099', 1, [$line], 'WEB-00099-W1')['prepaid'], 'not the invoice\'s');

        $correction = $builder->correctionOf($invoice, 'RE-00099', 1, [$line], 'WEB-00099-W1', $back);
        $y = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('creditNote', 'GS-00001'), $correction));
        // 30 credited, of which 12 went back onto the voucher with its tax.
        self::assertSame(['10.00'], $this->all($y, '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount', 2));
        self::assertSame(['3.00'], $this->all($y, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount', 2));
        self::assertSame('18.00', $y->evaluate('string(//ram:GrandTotalAmount)'));
    }

    /**
     * A voucher that covers a line exactly on a half cent: the line splits
     * 11.25 into 9.38 and 1.87, and so does the voucher's tax (taxIn()).
     * With the tax rounded instead (1.88) the rate kept a cent of tax on no
     * basis and neither the invoice nor its correction had an XML.
     */
    public function testEinHalberCentLaesstDenSatzAufNull(): void
    {
        $voucher = new SinglePurpose(1, '20', 'AT');
        $prepaid = static fn (string $gross): array => ['label' => 'Gutschein …MDSN', 'country' => 'AT', 'rate' => '20.0000', 'net' => bcsub($gross, $voucher->taxIn($gross, 2), 4), 'tax' => $voucher->taxIn($gross, 2), 'gross' => $gross];
        $taxes = '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax';

        $invoice = $this->payload([$this->line('Essig', '1.0000', '9.3750', '20.0000', 'AT'), $this->line('Buch', '1.0000', '10.0000', '10.0000', 'AT')]);
        $invoice['prepaid'] = [$prepaid('11.2500')];
        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $invoice));
        self::assertSame(['0.00', '10.00'], $this->all($x, $taxes.'/ram:BasisAmount', 2));
        self::assertSame(['0.00', '1.00'], $this->all($x, $taxes.'/ram:CalculatedAmount', 2));

        $back = $prepaid('11.2500');
        $back = ['net' => '-'.$back['net'], 'tax' => '-'.$back['tax'], 'gross' => '-11.2500'] + $back;
        $correction = (new DocumentPayloadBuilder())->correctionOf($invoice, 'RE-00099', 1, [$this->line('Essig', '1.0000', '-9.3750', '20.0000', 'AT')], 'WEB-00099-W1', [$back]);
        $y = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('creditNote', 'GS-00001'), $correction));
        self::assertSame(['0.00'], $this->all($y, $taxes.'/ram:CalculatedAmount', 2));
        self::assertSame('0.00', $y->evaluate('string(//ram:GrandTotalAmount)'));
    }

    /**
     * Reverse charge: goods are K (intra-community supply) with the exemption
     * reason, a service is AE.
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
     * With dompdf and embedded: the PDF carries exactly this XML.
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
     * R: the totals of the XML are those of the PDF — computed like
     * `summariseTax` (rounded lines, tax = gross − net), checked against the
     * tax summary of the snapshot, not against a calculation of the test's
     * own. Before: 3 × 8.3417 at 20 % gave 30.04 and 5.01 in the XML, printed
     * 30.03 and 5.00.
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

            // BR-CO-10: header = sum of the lines.
            $lines = '0';

            foreach ($this->all($x, '//ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', 2) as $amount) {
                $lines = bcadd($lines, $amount, 2);
            }

            self::assertSame($lines, $x->evaluate('string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount)'));
        }
    }

    /**
     * R (BR-CO-25): prepayment names no date — then the payment terms, the
     * title of the payment method as the order froze it. Nothing open: no
     * terms needed.
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
     * EZ-4 (F1): a single-purpose voucher is a document allowance at its
     * rate — the lines stay whole, the breakdown and the total carry only the
     * tax this invoice raises, and the voucher is not a prepayment as well.
     * Before: it was a payment, and the XML stated its tax a second time.
     */
    public function testEinEinzweckGutscheinIstEinAbschlagZuSeinemSatz(): void
    {
        $payload = $this->payload([
            $this->line('Tee', '1.0000', '50.0000', '20.0000', 'AT'),
            $this->line('Buch', '1.0000', '10.0000', '10.0000', 'AT'),
        ]);
        $payload['prepaid'] = [['label' => 'Gutschein …MDSN', 'country' => 'AT', 'rate' => '20.0000', 'net' => '20.0000', 'tax' => '4.0000', 'gross' => '24.0000']];
        $payload['payment']['payments'] = [['method' => 'Gutschein …MDSN', 'amount' => '24.0000'], ['method' => 'Karte', 'amount' => '10.0000']];

        $xml = (new ZugferdInvoice())->xml($this->document('invoice'), $payload);
        self::assertNotNull($xml, 'the schema accepts the allowance');
        $x = $this->xpath($xml);

        $allowance = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge';
        self::assertSame(['false'], $this->all($x, $allowance.'/ram:ChargeIndicator/udt:Indicator'));
        self::assertSame(['20.00'], $this->all($x, $allowance.'/ram:ActualAmount', 2));
        self::assertSame(['20'], $this->all($x, $allowance.'/ram:CategoryTradeTax/ram:RateApplicablePercent'));
        self::assertSame('Gutschein …MDSN', $x->evaluate('string('.$allowance.'/ram:Reason)'));

        // Per rate: 20 % on 50 − 20, 10 % untouched.
        $taxes = '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax';
        self::assertSame(['30.00', '10.00'], $this->all($x, $taxes.'/ram:BasisAmount', 2));
        self::assertSame(['6.00', '1.00'], $this->all($x, $taxes.'/ram:CalculatedAmount', 2));

        $sum = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:';
        self::assertSame(
            ['60.00', '20.00', '40.00', '7.00', '47.00', '10.00', '37.00'],
            array_map(fn (string $field): string => $this->all($x, $sum.$field, 2)[0], ['LineTotalAmount', 'AllowanceTotalAmount', 'TaxBasisTotalAmount', 'TaxTotalAmount', 'GrandTotalAmount', 'TotalPrepaidAmount', 'DuePayableAmount']),
        );
    }

    /**
     * EZ-4: a deduction the lines cannot carry — no line at its rate, or more
     * than the lines at it — gives no XML rather than one that disagrees
     * with the PDF. The same deduction within its lines gives one.
     */
    public function testEinAbzugOhnePassendeZeilenGibtKeinXml(): void
    {
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message) use (&$messages): void {
            $messages[] = (string) $message;
        });
        $payload = $this->payload([$this->line('Buch', '1.0000', '10.0000', '10.0000', 'AT'), $this->line('Tee', '1.0000', '10.0000', '20.0000', 'AT')]);
        $voucher = ['label' => 'Gutschein …MDSN', 'country' => 'AT', 'rate' => '20.0000'];

        $payload['prepaid'] = [$voucher + ['net' => '10.0000', 'tax' => '2.0000', 'gross' => '12.0000']];
        self::assertNotNull((new ZugferdInvoice($logger))->xml($this->document('invoice'), $payload));

        $payload['prepaid'] = [$voucher + ['net' => '20.0000', 'tax' => '4.0000', 'gross' => '24.0000']];
        self::assertNull((new ZugferdInvoice($logger))->xml($this->document('invoice'), $payload));

        $payload['prepaid'] = [['rate' => '13.0000'] + $voucher + ['net' => '5.0000', 'tax' => '0.6500', 'gross' => '5.6500']];
        self::assertNull((new ZugferdInvoice($logger))->xml($this->document('invoice'), $payload));

        self::assertCount(2, $messages);
        self::assertStringContainsString('single-purpose voucher', $messages[0]);
    }

    /**
     * R (BR-O-2): only "not taxable" — without VAT IDs.
     */
    public function testNurNichtSteuerbarOhneUid(): void
    {
        $payload = $this->payload([$this->line('Gutschein', '1.0000', '25.0000', '0.0000', '', source: 'notTaxable')]);
        $x = $this->xpath((string) (new ZugferdInvoice())->xml($this->document('invoice'), $payload));

        self::assertSame(['O'], $this->all($x, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'));
        self::assertSame(0.0, $x->evaluate('count(//ram:SpecifiedTaxRegistration)'));
    }

    /**
     * R (BR-IC-12): without a delivery address the goods went to the billing
     * address — the delivery country is stated anyway.
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
     * R: for each exit without XML a state in which only that exit
     * fires.
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

        // A delivery note is not an invoice — no XML, no log.
        self::assertNull($invoice->xml($this->document('deliveryNote'), $payload));
        self::assertSame([], $messages);

        // The issuer without a VAT ID — cleared after switching on.
        $withoutVatId = $payload;
        $withoutVatId['issuer']['vatId'] = '';
        self::assertNull($invoice->xml($this->document('invoice'), $withoutVatId));
        self::assertStringContainsString('company, country or VAT ID', implode("\n", $messages));

        // Control case: the same snapshot with a VAT ID gives XML.
        self::assertNotNull($invoice->xml($this->document('invoice'), $payload));
    }
    /**
     * R: a currency without decimal places computes like the PDF (to its
     * places), one with three gets no XML.
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
     * R: without a title of the payment method, the terms in the language of
     * the order.
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

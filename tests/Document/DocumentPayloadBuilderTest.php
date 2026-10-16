<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;

class DocumentPayloadBuilderTest extends TestCase
{
    private DocumentPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DocumentPayloadBuilder();
    }

    public function testCapturesOrderAndItems(): void
    {
        $payload = $this->builder->build($this->order(), $this->items());

        self::assertSame('WEB-00042', $payload['order']['orderNumber']);
        self::assertSame('Musterstraße 1', $payload['order']['billingStreet']);
        self::assertCount(3, $payload['items']);
        self::assertSame('Schwedenbitter', $payload['items'][0]['productName']);
    }

    /**
     * 🔴 F6 des Reviews vom 2026-09-23: **Die Art der Zeile gehört in den
     * Schnappschuss.**
     *
     * Vor C-7 war jede Zeile ein Artikel, also musste niemand es dazusagen.
     * Jetzt sind Versand, Zahlungsgebühr und Nachlass eigene Zeilen — und eine
     * Rechnung, die sie nicht unterscheiden kann, setzt „Paket" mitten in die
     * Artikelliste, mit leerer Artikelnummer daneben.
     *
     * Jetzt und nicht mit C-8: Ein Schnappschuss wird einmal geschrieben.
     */
    public function testDieArtDerZeileStehtImSchnappschuss(): void
    {
        $payload = $this->builder->build($this->order(), $this->items());

        self::assertSame('product', $payload['items'][0]['lineType']);
        self::assertSame('shipping', $payload['items'][2]['lineType']);
    }

    /**
     * Und die Fassung dazu — für den, der das in ein paar Jahren liest.
     *
     * Die sieben Rechnungen, die auf c5 schon liegen, kennen weder das eine
     * noch das andere: Wer keine `formatVersion` findet, liest Fassung 1 ohne
     * `lineType`, und dort war jede Zeile ein Artikel.
     */
    public function testDerSchnappschussSagtInWelcherFassungErGeschriebenWurde(): void
    {
        self::assertSame(10, $this->builder->build($this->order(), $this->items())['formatVersion']);
    }

    /**
     * Fassung 7 (Abo-4): Die Rechnung einer Folgebestellung nennt Abo und
     * Leistungszeitraum; jede andere trägt null.
     */
    public function testDieFolgerechnungNenntAboUndLeistungszeitraum(): void
    {
        $subscription = ['number' => 'WEB-00084-1', 'from' => '2026-10-29', 'to' => '2026-11-29'];

        self::assertSame($subscription, $this->builder->build($this->order(), $this->items(), ['subscription' => $subscription])['subscription']);
        self::assertNull($this->builder->build($this->order(), $this->items())['subscription']);
    }

    /**
     * Abo-2 (P13): Die Bedingungen eines Abos gehören auf die Rechnung — und
     * in den Schnappschuss, weil eine später neu erzeugte Rechnung sonst ohne
     * sie dastünde. Die Id der Regel bleibt draußen wie `customerGroup`.
     */
    public function testDieAbobedingungenStehenImSchnappschussDieRegelNicht(): void
    {
        $items = $this->items();
        $items[0]['subscriptionTerms'] = '1,00 € / Monat · Laufzeit 1 Jahr · endet danach';
        $items[0]['subscriptionRule'] = '5';

        $payload = $this->builder->build($this->order(), $items);

        self::assertSame('1,00 € / Monat · Laufzeit 1 Jahr · endet danach', $payload['items'][0]['subscriptionTerms']);
        self::assertArrayNotHasKey('subscriptionRule', $payload['items'][0]);
    }

    /**
     * Fassung 2 (C-8): Absender, Logo, Zahlung und Betreibertexte stehen im
     * Schnappschuss — die Vorlage liefert nie Daten (8.1).
     */
    public function testFassungZweiTraegtDenKontextDerRechnung(): void
    {
        $payload = $this->builder->build($this->order(), $this->items(), $this->context());

        self::assertSame('Muster GmbH', $payload['issuer']['company']);
        self::assertSame('ATU12345678', $payload['issuer']['vatId']);
        self::assertSame(['uuid' => 'uuid', 'hash' => 'hash'], $payload['logo']);
        self::assertSame('AT61 1904 3002 3457 3201', $payload['payment']['details']['iban']);
        self::assertSame('Firmenbuch FN 1a', $payload['notes']['footer']);
    }

    /**
     * 🔴 Der Reverse-Charge-Hinweis erscheint NUR mit einer Reverse-Charge-
     * Zeile. Auf einer gewöhnlichen Rechnung behauptete er etwas über diesen
     * Verkauf, das nicht stimmt.
     */
    public function testDerReverseChargeHinweisNurMitReverseChargeZeile(): void
    {
        self::assertSame('', $this->builder->build($this->order(), $this->items(), $this->context())['notes']['reverseCharge']);

        $items = $this->items();
        $items[0]['priceSource'] = 'reverseCharge';

        self::assertSame(
            'Steuerschuldnerschaft des Leistungsempfängers',
            $this->builder->build($this->order(), $items, $this->context())['notes']['reverseCharge'],
        );
    }

    /**
     * Ohne Kontext — die Fassung-1-Aufrufer — entsteht trotzdem ein
     * vollständiger Schnappschuss mit leeren Teilen, kein halber.
     */
    public function testOhneKontextSindDieTeileLeerNichtFehlend(): void
    {
        $payload = $this->builder->build($this->order(), $this->items());

        self::assertSame([], $payload['issuer']);
        self::assertNull($payload['logo']);
        self::assertSame(['instructions' => '', 'details' => [], 'meansCode' => 'ZZZ'], $payload['payment']);
        self::assertFalse($payload['eInvoice']);
        self::assertSame(['footer' => '', 'reverseCharge' => ''], $payload['notes']);
    }

    /**
     * Eine Zeile aus der Zeit davor — der Bauplan darf daran nicht scheitern.
     */
    public function testEineZeileOhneArtWirdLeerUebernommen(): void
    {
        $item = $this->items()[0];
        unset($item['lineType']);

        self::assertSame('', $this->builder->build($this->order(), [$item])['items'][0]['lineType']);
    }

    public function testCapturesTaxRatesPerItemRatherThanOnePerDocument(): void
    {
        $payload = $this->builder->build($this->order(), $this->items());

        self::assertSame('20.0000', $payload['items'][0]['taxRate']);
        self::assertSame('10.0000', $payload['items'][1]['taxRate']);
    }

    public function testCapturesThePriceSourceOfEveryItem(): void
    {
        $payload = $this->builder->build($this->order(), $this->items());

        self::assertSame('list', $payload['items'][0]['priceSource']);
        self::assertSame('customerGroup', $payload['items'][1]['priceSource']);
    }

    /**
     * An invoice has to show the tax broken down per rate, not as one sum. An
     * order mixing the standard and the reduced rate is the everyday case in
     * food and book retail.
     */
    public function testGroupsTotalsByTaxRate(): void
    {
        $payload = $this->builder->build($this->order(), $this->items());

        // Der Versand zählt zum Satz seiner Zeile, nicht in einen eigenen
        // Topf: 50 + 5 netto zu 20 %, 20 netto zu 10 %.
        self::assertSame(
            [
                '10.0000' => ['net' => '20.00', 'tax' => '2.00', 'gross' => '22.00'],
                '20.0000' => ['net' => '55.00', 'tax' => '11.00', 'gross' => '66.00'],
            ],
            $payload['taxSummary'],
        );
    }

    /**
     * 🔴 Die Steuer je Satz ist Brutto − Netto JE SATZ (Michael, 2026-09-24:
     * „Je Satz: Brutto − Netto").
     *
     * Zwei Zeilen à 8,3367 netto zu 20 %: Die Zeilensteuern ergeben gerundet
     * 1,67 + 1,67 = 3,34, die Bestellung aber — Brutto 10,00 + 10,00 = 20,00,
     * Netto 8,34 + 8,34 = 16,68 — trägt 3,32 als taxTotal. Die alte Übersicht
     * zeigte 3,3346, also gerundet 3,33, und widersprach der eigenen Summe.
     */
    public function testDieSteuerJeSatzIstBruttoMinusNettoUndGehtAufTaxTotalAuf(): void
    {
        $line = [
            'productName' => 'Wanderstock', 'sku' => '', 'quantity' => '1.0000', 'unitPrice' => '8.3367',
            'taxRate' => '20.0000', 'taxAmount' => '1.6673', 'lineTotal' => '10.0040', 'priceSource' => 'list',
            'lineType' => 'product',
        ];
        $order = ['currency' => 'EUR', 'netTotal' => '16.68', 'taxTotal' => '3.32', 'grandTotal' => '20.00'];

        $payload = $this->builder->build($order, [$line, $line]);

        self::assertSame(['20.0000' => ['net' => '16.68', 'tax' => '3.32', 'gross' => '20.00']], $payload['taxSummary']);
    }

    /**
     * DL4 (Michael, 2026-10-01: „Je Land und Satz"): FR 20 % am E-Book und
     * AT 20 % an der Beratung sind zwei Zeilen, nicht eine „20 %". Eine
     * Rechnung mit einem Land bleibt beim Satz allein; null Prozent (Reverse
     * Charge) wird nie nach Land getrennt. Die Steuer aller Zeilen bleibt
     * taxTotal.
     */
    public function testZweiSteuerlaenderMitGleichemSatzBleibenGetrennt(): void
    {
        $line = static fn (string $country, string $rate, string $tax, string $total): array => [
            'productName' => 'x', 'sku' => '', 'quantity' => '1.0000', 'unitPrice' => '10.0000', 'taxRate' => $rate,
            'taxAmount' => $tax, 'lineTotal' => $total, 'priceSource' => 'list', 'lineType' => 'product', 'taxCountry' => $country,
        ];

        $items = [
            $line('FR', '20.0000', '2.0000', '12.0000'),
            $line('AT', '20.0000', '2.0000', '12.0000'),
            $line('FR', '5.5000', '0.5500', '10.5500'),
        ];

        $summary = $this->builder->build(['currency' => 'EUR'], $items)['taxSummary'];

        self::assertSame(['AT:20.0000', 'FR:5.5000', 'FR:20.0000'], array_keys($summary));
        self::assertSame(['net' => '10.00', 'tax' => '2.00', 'gross' => '12.00'], $summary['AT:20.0000']);
        self::assertSame(['20.0000'], array_keys($this->builder->build(['currency' => 'EUR'], [$line('AT', '20.0000', '2.0000', '12.0000'), $line('AT', '20.0000', '2.0000', '12.0000')])['taxSummary']), 'ein Land: wie bisher');
        self::assertSame(['0.0000'], array_keys($this->builder->build(['currency' => 'EUR'], [$line('FR', '0.0000', '0.0000', '10.0000'), $line('AT', '0.0000', '0.0000', '10.0000')])['taxSummary']), 'Reverse Charge: nicht getrennt');
        self::assertSame('FR', $this->builder->build(['currency' => 'EUR'], $items)['items'][0]['taxCountry'], 'eingefroren je Zeile');
    }

    /**
     * Und über mehrere Sätze: Die Summe der Steuer je Satz ist GENAU taxTotal,
     * ohne dass ein Rest-Cent einem Satz zugeschlagen werden muss — weil
     * Netto und Brutto je Satz aus denselben gerundeten Zeilen stammen wie die
     * Summen der Bestellung.
     */
    public function testDieSteuerAllerSaetzeErgibtTaxTotal(): void
    {
        $items = [];

        foreach (['10.0000' => ['8.3367', '0.8337'], '20.0000' => ['8.3367', '1.6673'], '13.0000' => ['4.4247', '0.5752']] as $rate => [$net, $tax]) {
            $items[] = [
                'productName' => 'x', 'sku' => '', 'quantity' => '1.0000', 'unitPrice' => $net, 'taxRate' => $rate,
                'taxAmount' => $tax, 'lineTotal' => (string) BigDecimal::of($net)->plus($tax), 'priceSource' => 'list',
                'lineType' => 'product',
            ];
        }

        $netTotal = BigDecimal::zero();
        $grandTotal = BigDecimal::zero();

        foreach ($items as $item) {
            $netTotal = $netTotal->plus(BigDecimal::of($item['lineTotal'])->minus($item['taxAmount'])->toScale(2, RoundingMode::HalfUp));
            $grandTotal = $grandTotal->plus(BigDecimal::of($item['lineTotal'])->toScale(2, RoundingMode::HalfUp));
        }

        $payload = $this->builder->build(['currency' => 'EUR'], $items);

        $tax = BigDecimal::zero();

        foreach ($payload['taxSummary'] as $entry) {
            $tax = $tax->plus($entry['tax']);
        }

        self::assertTrue($tax->isEqualTo($grandTotal->minus($netTotal)));
    }

    /**
     * Die Stellen kommen aus der Währung, nicht fest zwei: Yen haben keine.
     */
    public function testDieStellenDerUebersichtKommenAusDerWaehrung(): void
    {
        $item = [
            'productName' => 'x', 'sku' => '', 'quantity' => '1.0000', 'unitPrice' => '1000.4000', 'taxRate' => '10.0000',
            'taxAmount' => '100.0400', 'lineTotal' => '1100.4400', 'priceSource' => 'list', 'lineType' => 'product',
        ];

        $payload = $this->builder->build(['currency' => 'JPY'], [$item]);

        self::assertSame(['net' => '1000', 'tax' => '100', 'gross' => '1100'], $payload['taxSummary']['10.0000']);
    }

    /**
     * The document must stand on its own. A product id in the payload would
     * invite someone to resolve it later — and find nothing.
     */
    public function testDoesNotStoreAnyIdsThatCouldDangle(): void
    {
        $payload = $this->builder->build($this->order(), $this->items());

        self::assertArrayNotHasKey('product', $payload['items'][0]);
        self::assertArrayNotHasKey('member', $payload['order']);
    }

    /**
     * @return array<string, string>
     */
    private function order(): array
    {
        return [
            'orderNumber' => 'WEB-00042',
            'currency' => 'EUR',
            'firstname' => 'Michael',
            'lastname' => 'Morgenbesser',
            'company' => '',
            'billingStreet' => 'Musterstraße 1',
            'billingPostal' => '1100',
            'billingCity' => 'Wien',
            'billingCountry' => 'AT',
            'netTotal' => '70.0000',
            'taxTotal' => '12.0000',
            'grandTotal' => '82.0000',
            'vatId' => '',
            'member' => '7',
        ];
    }

    /**
     * @return array{issuer: array<string, string>, logo: array{uuid: string, hash: string}, payment: array{instructions: string, details: array<string, string>}, notes: array{footer: string, reverseCharge: string}}
     */
    private function context(): array
    {
        return [
            'issuer' => ['company' => 'Muster GmbH', 'vatId' => 'ATU12345678'],
            'logo' => ['uuid' => 'uuid', 'hash' => 'hash'],
            'payment' => ['instructions' => '', 'details' => ['iban' => 'AT61 1904 3002 3457 3201']],
            'notes' => ['footer' => 'Firmenbuch FN 1a', 'reverseCharge' => 'Steuerschuldnerschaft des Leistungsempfängers'],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function items(): array
    {
        return [
            [
                'product' => '5',
                'productName' => 'Schwedenbitter',
                'sku' => 'SB-250',
                'quantity' => '1.0000',
                'unitPrice' => '50.0000',
                'taxRate' => '20.0000',
                'taxAmount' => '10.0000',
                'lineTotal' => '60.0000',
                'priceSource' => 'list',
                'lineType' => 'product',
            ],
            [
                'product' => '6',
                'productName' => 'Kräutertee',
                'sku' => 'KT-100',
                'quantity' => '2.0000',
                'unitPrice' => '10.0000',
                'taxRate' => '10.0000',
                'taxAmount' => '2.0000',
                'lineTotal' => '22.0000',
                'priceSource' => 'customerGroup',
                'lineType' => 'product',
            ],
            [
                'productName' => 'Paket',
                'sku' => '',
                'quantity' => '1.0000',
                'unitPrice' => '5.0000',
                'taxRate' => '20.0000',
                'taxAmount' => '1.0000',
                'lineTotal' => '6.0000',
                'priceSource' => '',
                'lineType' => 'shipping',
            ],
        ];
    }
}

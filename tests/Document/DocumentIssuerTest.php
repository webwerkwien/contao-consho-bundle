<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DeliveryPeriod;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentSourceInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\PaymentTerm;
use Webwerkwien\ContaoConshoBundle\Document\RelatedDocumentRequired;
use Webwerkwien\ContaoConshoBundle\Order\NumberAllocatorInterface;

class DocumentIssuerTest extends TestCase
{
    public function testIssuingAnInvoiceDrawsFromTheInvoiceRange(): void
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator
            ->expects(self::once())
            ->method('next')
            ->with(3, 'invoice')
            ->willReturn('RE-00007')
        ;

        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn(['orderNumber' => 'WEB-1']);
        $repository->method('findOrderItemRows')->willReturn([]);
        $repository
            ->expects(self::once())
            ->method('insert')
            ->with(1, 3, DocumentType::Invoice, 'RE-00007')
            ->willReturn(99)
        ;

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        self::assertSame(99, $issuer->issue(1, 3, DocumentType::Invoice));
    }

    /**
     * An invoice sequence has to be unbroken for the tax office. Delivery notes
     * sharing it would tear holes in exactly the sequence that may not have
     * any.
     */
    public function testADeliveryNoteDrawsFromItsOwnRange(): void
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::once())->method('next')->with(3, 'deliveryNote')->willReturn('LS-00001');

        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn([]);
        $repository->method('findOrderItemRows')->willReturn([]);
        $repository->method('insert')->willReturn(100);

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        $issuer->issue(1, 3, DocumentType::DeliveryNote);
    }

    public function testIssueOnceReturnsTheExistingDocumentWithoutAllocatingANumber(): void
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findIdByOrderAndType')->willReturn(42);
        $repository->expects(self::never())->method('insert');

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        self::assertSame(42, $issuer->issueOnce(1, 3, DocumentType::Invoice));
    }

    public function testIssueOnceCreatesTheDocumentWhenThereIsNone(): void
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->method('next')->willReturn('RE-00001');

        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findIdByOrderAndType')->willReturn(null);
        $repository->method('findOrderRow')->willReturn([]);
        $repository->method('findOrderItemRows')->willReturn([]);
        $repository->expects(self::once())->method('insert')->willReturn(7);

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        self::assertSame(7, $issuer->issueOnce(1, 3, DocumentType::Invoice));
    }

    public function testACreditNoteWithoutAReferenceIsRefused(): void
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        $repository = $this->createMock(DocumentRepositoryInterface::class);

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        $this->expectException(RelatedDocumentRequired::class);

        $issuer->issue(1, 3, DocumentType::CreditNote);
    }

    public function testACancellationWithAReferenceIsAccepted(): void
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->method('next')->willReturn('ST-00001');

        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn([]);
        $repository->method('findOrderItemRows')->willReturn([]);
        $repository
            ->expects(self::once())
            ->method('insert')
            ->with(1, 3, DocumentType::Cancellation, 'ST-00001', self::anything(), 42)
            ->willReturn(8)
        ;

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        self::assertSame(8, $issuer->issue(1, 3, DocumentType::Cancellation, 42));
    }

    /**
     * C-10 (7.5): Der Storno entsteht aus dem Schnappschuss DER RECHNUNG, nicht
     * aus der Bestellung — die ist beim Wechsel der Zahlungsart schon umgestellt
     * oder wird es gleich. Eine Rechnung ändert sich nie, also darf auch ihr
     * Gegenstück nichts anderes sagen als sie.
     */
    public function testDerStornoSpiegeltDenSchnappschussDerRechnung(): void
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findDocumentRow')->with(42)->willReturn($this->invoiceRow());
        $repository->method('findCancellationOf')->willReturn(null);

        // Die Bestellung wird nicht gefragt.
        $repository->expects(self::never())->method('findOrderRow');
        $repository->expects(self::never())->method('findOrderItemRows');

        $captured = [];
        $repository
            ->expects(self::once())
            ->method('insert')
            ->with(5, 3, DocumentType::Cancellation, 'ST-00002', self::anything(), 42)
            ->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $i, int $r, array $payload) use (&$captured): int {
                $captured = $payload;

                return 43;
            })
        ;

        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::once())->method('next')->with(3, 'cancellation')->willReturn('ST-00002');

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        self::assertSame(43, $issuer->cancel(42));

        self::assertSame('-12.00', $captured['order']['grandTotal']);
        self::assertSame('-10.00', $captured['order']['netTotal']);
        self::assertSame('-2.00', $captured['order']['taxTotal']);
        self::assertSame('-10.0000', $captured['items'][0]['unitPrice']);
        self::assertSame('-2.0000', $captured['items'][0]['taxAmount']);
        self::assertSame('-12.0000', $captured['items'][0]['lineTotal']);

        // Die Menge bleibt: storniert wird ein Stück zu minus zehn, nicht
        // minus ein Stück — sonst höben sich die beiden Vorzeichen auf.
        self::assertSame('1.0000', $captured['items'][0]['quantity']);
        self::assertSame(['net' => '-10.00', 'tax' => '-2.00', 'gross' => '-12.00'], $captured['taxSummary']['20.0000']);

        self::assertSame(['number' => 'RE-00001', 'issuedAt' => '1790000000'], $captured['cancels']);

        // Ein Storno fordert keine Zahlung: kein Zahlungsblock, kein Giro-Code,
        // kein Lieferzeitraum.
        self::assertSame(['instructions' => '', 'details' => []], $captured['payment']);
        self::assertNull($captured['delivery']);

        // Absender und Empfänger wie auf der Rechnung, nicht wie heute.
        self::assertSame(['company' => 'Damals GmbH'], $captured['issuer']);
        self::assertSame('Muster', $captured['order']['lastname']);
    }

    /**
     * D-3 (F2, F3): die Rechnungskorrektur — Aussteller und Kunde aus dem
     * Schnappschuss der Rechnung, die Zeilen aus dem Widerruf, die Summen aus
     * der Steuerzusammenfassung dieser Zeilen, eigener Nummernkreis.
     */
    public function testDieRechnungskorrekturNimmtDieZeilenDesWiderrufs(): void
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findDocumentRow')->with(42)->willReturn($this->invoiceRow());
        $repository->expects(self::never())->method('findOrderRow');

        $captured = [];
        $repository
            ->expects(self::once())
            ->method('insert')
            ->with(5, 3, DocumentType::CreditNote, 'RK-00001', self::anything(), 42)
            ->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $i, int $r, array $payload) use (&$captured): int {
                $captured = $payload;

                return 44;
            })
        ;

        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::once())->method('next')->with(3, 'creditNote')->willReturn('RK-00001');

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        self::assertSame(44, $issuer->correct(42, [
            ['productName' => 'Wanderstock', 'sku' => 'WS', 'quantity' => '1', 'unitPrice' => '-5.0000', 'taxRate' => '20.0000', 'taxAmount' => '-1.0000', 'lineTotal' => '-6.0000', 'priceSource' => 'list', 'lineType' => 'product'],
            ['productName' => 'Paket', 'sku' => '', 'quantity' => '1', 'unitPrice' => '-2.5000', 'taxRate' => '20.0000', 'taxAmount' => '-0.5000', 'lineTotal' => '-3.0000', 'priceSource' => '', 'lineType' => 'shipping'],
        ], 'WEB-1-W1'));

        self::assertSame('-9.00', $captured['order']['grandTotal']);
        self::assertSame('-7.50', $captured['order']['netTotal']);
        self::assertSame('-1.50', $captured['order']['taxTotal']);
        self::assertCount(2, $captured['items']);
        self::assertSame(['number' => 'RE-00001', 'issuedAt' => '1790000000'], $captured['corrects']);
        self::assertSame('WEB-1-W1', $captured['withdrawal']);
        self::assertArrayNotHasKey('cancels', $captured);
        self::assertSame(['instructions' => '', 'details' => []], $captured['payment']);
        self::assertSame(['company' => 'Damals GmbH'], $captured['issuer']);
    }

    public function testEineKorrekturOhneZeilenWirdAbgelehnt(): void
    {
        $repository = $this->createStub(DocumentRepositoryInterface::class);
        $repository->method('findDocumentRow')->willReturn($this->invoiceRow());

        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        $this->expectException(\InvalidArgumentException::class);

        (new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class)))->correct(42, [], 'W');
    }

    public function testEineStornierteRechnungWirdNichtZweimalStorniert(): void
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findDocumentRow')->willReturn($this->invoiceRow());
        $repository->method('findCancellationOf')->with(42)->willReturn(43);
        $repository->expects(self::never())->method('insert');

        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        self::assertSame(43, $issuer->cancel(42));
    }

    public function testNurEineRechnungLaesstSichStornieren(): void
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findDocumentRow')->willReturn(['type' => 'cancellation'] + $this->invoiceRow());

        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        $issuer = new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $this->createStub(DocumentSourceInterface::class));

        $this->expectException(\InvalidArgumentException::class);

        $issuer->cancel(42);
    }

    /**
     * @return array<string, string>
     */
    private function invoiceRow(): array
    {
        return [
            'id' => '42',
            'pid' => '5',
            'shop' => '3',
            'type' => 'invoice',
            'documentNumber' => 'RE-00001',
            'issuedAt' => '1790000000',
            'payload' => json_encode([
                'formatVersion' => 3,
                'order' => ['orderNumber' => 'WEB-1', 'currency' => 'EUR', 'lastname' => 'Muster', 'netTotal' => '10.00', 'taxTotal' => '2.00', 'grandTotal' => '12.00'],
                'items' => [[
                    'productName' => 'Schwedenbitter', 'sku' => 'SB', 'quantity' => '1.0000', 'unitPrice' => '10.0000',
                    'taxRate' => '20.0000', 'taxAmount' => '2.0000', 'lineTotal' => '12.0000', 'priceSource' => 'list', 'lineType' => 'product',
                ]],
                'taxSummary' => ['20.0000' => ['net' => '10.00', 'tax' => '2.00', 'gross' => '12.00']],
                'issuer' => ['company' => 'Damals GmbH'],
                'logo' => null,
                'delivery' => ['from' => '2026-09-26', 'to' => '2026-09-30'],
                'payment' => ['instructions' => '<p>Bitte überweisen</p>', 'details' => ['iban' => 'AT00'], 'giroCode' => "BCD\n002"],
                'notes' => ['footer' => 'Fuß', 'reverseCharge' => ''],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The test this whole plan rests on.
     *
     * Were the number drawn before the check, every refused credit note would
     * leave a hole in precisely the sequence that has to be unbroken. The order
     * of the two steps is the requirement, not a matter of style.
     */
    public function testTheNumberIsAllocatedOnlyAfterTheReferenceCheck(): void
    {
        $allocator = $this->createMock(NumberAllocatorInterface::class);
        $allocator->expects(self::never())->method('next');

        $issuer = new DocumentIssuer(
            $this->createMock(DocumentRepositoryInterface::class),
            $allocator,
            new DocumentPayloadBuilder(),
            $this->createStub(DocumentSourceInterface::class),
        );

        $this->expectException(RelatedDocumentRequired::class);

        $issuer->issue(1, 3, DocumentType::CreditNote);
    }

    /**
     * C-8: Was der Beleg über die Bestellung hinaus sagt — Absender, Logo,
     * Zahlung, Betreibertexte —, liest die Quelle beim Ausstellen, mit der
     * Bestellzeile als Frage, und es landet im Schnappschuss.
     */
    public function testDerKontextDerQuelleLandetImSchnappschuss(): void
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn(['orderNumber' => 'WEB-1', 'shop' => '3', 'language' => 'de']);
        $repository->method('findOrderItemRows')->willReturn([]);

        $source = $this->createMock(DocumentSourceInterface::class);
        $source->expects(self::once())->method('contextFor')->with(['orderNumber' => 'WEB-1', 'shop' => '3', 'language' => 'de'])->willReturn([
            'issuer' => ['company' => 'Muster GmbH'],
            'logo' => ['uuid' => 'a', 'hash' => 'b'],
            'payment' => ['instructions' => '<p>Bitte überweisen</p>', 'details' => ['iban' => 'AT00']],
            'notes' => ['footer' => 'FN 1a', 'reverseCharge' => 'RC'],
        ]);

        $captured = null;
        $repository->method('insert')->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $i, int $r, array $payload) use (&$captured): int {
            $captured = $payload;

            return 1;
        });

        $allocator = $this->createStub(NumberAllocatorInterface::class);
        $allocator->method('next')->willReturn('RE-1');

        (new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $source))->issue(1, 3, DocumentType::Invoice);

        self::assertSame('Muster GmbH', $captured['issuer']['company'] ?? null);
        self::assertSame(['uuid' => 'a', 'hash' => 'b'], $captured['logo'] ?? null);
        self::assertSame('AT00', $captured['payment']['details']['iban'] ?? null);
        self::assertSame('FN 1a', $captured['notes']['footer'] ?? null);
    }

    /**
     * C-8, Liefertag: Die Rechnung bekommt den voraussichtlichen
     * Lieferzeitraum, gezählt ab dem Tag der Ausstellung — ein Storno nicht,
     * er verweist auf eine Rechnung, die ihn schon nennt.
     */
    public function testNurDieRechnungNenntDenLieferzeitraum(): void
    {
        $payloads = [];

        foreach ([DocumentType::Invoice, DocumentType::Cancellation] as $type) {
            $repository = $this->createMock(DocumentRepositoryInterface::class);
            $repository->method('findOrderRow')->willReturn(['orderNumber' => 'WEB-1']);
            $repository->method('findOrderItemRows')->willReturn([]);
            $repository->method('insert')->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $issuedAt, int $r, array $payload) use (&$payloads): int {
                $payloads[$t->value] = [$issuedAt, $payload['delivery'] ?? null];

                return 1;
            });

            $source = $this->createStub(DocumentSourceInterface::class);
            $source->method('contextFor')->willReturn([
                'issuer' => [], 'logo' => null, 'payment' => ['instructions' => '', 'details' => []],
                'notes' => ['footer' => '', 'reverseCharge' => ''], 'deliveryDays' => ['min' => 2, 'max' => 5],
            ]);

            $allocator = $this->createStub(NumberAllocatorInterface::class);
            $allocator->method('next')->willReturn('X-1');

            (new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $source))->issue(1, 3, $type, DocumentType::Cancellation === $type ? 7 : null);
        }

        [$issuedAt, $delivery] = $payloads['invoice'];
        self::assertSame([...(array) DeliveryPeriod::of($issuedAt, 2, 5), 'kind' => 'expected'], $delivery);
        self::assertNull($payloads['cancellation'][1]);
    }

    /**
     * E-6b (Michael, 2026-09-28): Ist schon eine Sendung gemeldet, nennt die
     * Rechnung den Tag der Lieferung — nach einer Teillieferung dazu den Tag,
     * an dem der Rest voraussichtlich folgt.
     */
    public function testNachDerSendungDasLieferdatum(): void
    {
        $shippedAt = (new \DateTimeImmutable('2026-10-01 10:00:00'))->getTimestamp();

        self::assertSame(['from' => date('Y-m-d', $shippedAt), 'to' => date('Y-m-d', $shippedAt), 'kind' => 'delivered'],
            $this->deliveryAfter(['shippedAt' => $shippedAt, 'complete' => true, 'remainderDays' => 5]));

        // Donnerstag + 5 Werktage = Donnerstag der Folgewoche.
        $partial = $this->deliveryAfter(['shippedAt' => $shippedAt, 'complete' => false, 'remainderDays' => 5]);
        self::assertSame(['from', 'to', 'kind'], array_keys($partial));
        self::assertSame('partial', $partial['kind']);
        self::assertSame(DeliveryPeriod::shipped($shippedAt, false, 5)['to'], $partial['to']);
        self::assertNotSame($partial['from'], $partial['to']);
    }

    /**
     * @param array{shippedAt: int, complete: bool, remainderDays: int} $shipment
     *
     * @return array<string, string>
     */
    private function deliveryAfter(array $shipment): array
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn(['orderNumber' => 'WEB-1', 'currency' => 'EUR', 'grandTotal' => '13.9000']);
        $repository->method('findOrderItemRows')->willReturn([]);

        $captured = [];
        $repository->method('insert')->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $i, int $r, array $payload) use (&$captured): int {
            $captured = $payload;

            return 1;
        });

        $source = $this->createStub(DocumentSourceInterface::class);
        $source->method('contextFor')->willReturn([
            'issuer' => [], 'logo' => null, 'payment' => ['instructions' => '', 'details' => []],
            'notes' => ['footer' => '', 'reverseCharge' => ''], 'deliveryDays' => ['min' => 2, 'max' => 5],
            'shipment' => $shipment,
        ]);

        $allocator = $this->createStub(NumberAllocatorInterface::class);
        $allocator->method('next')->willReturn('RE-1');

        (new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $source))->issue(1, 3, DocumentType::Invoice);

        return \is_array($captured['delivery'] ?? null) ? $captured['delivery'] : [];
    }

    /**
     * Giro-Code (C-8): Der Wunsch der Zahlungsart wird zum fertigen Inhalt,
     * mit der eben vergebenen Rechnungsnummer als Verwendungszweck. Ohne
     * Wunsch keiner — und ein Storno fordert keine Zahlung, also auch dann
     * keiner, wenn die Zahlungsart ihn will.
     */
    public function testNurDieRechnungMitDemWunschTraegtDenGiroCode(): void
    {
        $bank = ['accountHolder' => 'Muster GmbH', 'iban' => 'AT611904300234573201'];

        self::assertSame(
            "BCD\n002\n1\nSCT\n\nMuster GmbH\nAT611904300234573201\nEUR13.90\n\n\nRE-00013",
            $this->issueWith(DocumentType::Invoice, true, $bank)['payment']['giroCode'] ?? null,
        );
        self::assertArrayNotHasKey('giroCode', $this->issueWith(DocumentType::Invoice, false, $bank)['payment']);
        self::assertArrayNotHasKey('giroCode', $this->issueWith(DocumentType::Cancellation, true, $bank)['payment']);

        // Der Wunsch selbst landet nie im Schnappschuss, nur der Code.
        self::assertSame(['instructions', 'details', 'meansCode'], array_keys($this->issueWith(DocumentType::Invoice, false, $bank)['payment']));
    }

    /**
     * Passen die Daten nicht (hier: keine IBAN), entsteht die Rechnung
     * trotzdem — ohne Code. Die Nummer ist da schon vergeben; ein Abbruch
     * hinterließe eine Lücke im Nummernkreis.
     */
    public function testOhneGueltigeBankdatenEntstehtDieRechnungOhneCode(): void
    {
        self::assertArrayNotHasKey('giroCode', $this->issueWith(DocumentType::Invoice, true, ['accountHolder' => 'Muster GmbH'])['payment']);
    }

    /**
     * F3b: Die mit einer Erstattung ausgestellte Rechnung nennt die ganze
     * Bestellung und wird sofort korrigiert — ein Code über ihren Betrag
     * verlangte zu viel. Trotz Wunsch der Zahlungsart also keiner.
     */
    public function testDieRechnungZurErstattungTraegtKeinenGiroCode(): void
    {
        $bank = ['accountHolder' => 'Muster GmbH', 'iban' => 'AT611904300234573201'];

        self::assertArrayNotHasKey('giroCode', $this->issueWith(DocumentType::Invoice, true, $bank, false)['payment']);
    }

    /**
     * E-2 (Z1, Z5): Eine Rechnung „Auf Rechnung" nennt „Zahlbar bis" —
     * Kalendertage ab dem Ausstellungstag — und die Bestellung merkt sich das
     * Ende dieses Tages. Jede Rechnung, die Zahlung verlangt, schreibt den
     * Wert, eine ohne Ziel 0: sonst bliebe nach einem Wechsel der Zahlungsart
     * das Ziel der alten stehen.
     */
    public function testDasZahlungszielWirdEingefrorenUndAnDerBestellungVermerkt(): void
    {
        foreach ([[14, true], [null, false]] as [$days, $hasTerm]) {
            $due = [];
            $payload = $this->issueForTerm($days, true, $due);

            self::assertSame($hasTerm, isset($payload['payment']['dueDate']), var_export($days, true));
            self::assertCount(1, $due);

            if ($hasTerm) {
                $expected = PaymentTerm::of($due[0]['issuedAt'], 14);
                self::assertSame($expected['date'], $payload['payment']['dueDate']);
                self::assertSame($expected['endsAt'], $due[0]['dueAt']);
            } else {
                self::assertSame(0, $due[0]['dueAt']);
            }
        }
    }

    /**
     * Die Rechnung, die mit einer Erstattung entsteht (D-3, F3b), fordert
     * keine Zahlung — also auch kein Ziel, und die Bestellung bleibt unberührt.
     */
    public function testDieRechnungZurErstattungNenntKeinZiel(): void
    {
        $due = [];
        $payload = $this->issueForTerm(14, false, $due);

        self::assertArrayNotHasKey('dueDate', $payload['payment']);
        self::assertSame([], $due);
    }

    /**
     * @param list<array{issuedAt: int, dueAt: int}> $due
     *
     * @return array<string, mixed>
     */
    private function issueForTerm(int|null $days, bool $asksPayment, array &$due): array
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn(['orderNumber' => 'WEB-1', 'currency' => 'EUR', 'grandTotal' => '13.9000']);
        $repository->method('findOrderItemRows')->willReturn([]);

        $captured = [];
        $issuedAt = 0;
        $repository->method('insert')->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $i, int $r, array $payload) use (&$captured, &$issuedAt): int {
            $captured = $payload;
            $issuedAt = $i;

            return 1;
        });
        $repository->method('recordPaymentDue')->willReturnCallback(static function (int $orderId, int $dueAt) use (&$due, &$issuedAt): void {
            $due[] = ['issuedAt' => $issuedAt, 'dueAt' => $dueAt];
        });

        $source = $this->createStub(DocumentSourceInterface::class);
        $source->method('contextFor')->willReturn([
            'issuer' => [], 'logo' => null, 'payment' => ['instructions' => '', 'details' => [], 'giroCode' => false, 'dueDays' => $days],
            'notes' => ['footer' => '', 'reverseCharge' => ''],
        ]);

        $allocator = $this->createStub(NumberAllocatorInterface::class);
        $allocator->method('next')->willReturn('RE-00014');

        (new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $source))->issue(1, 3, DocumentType::Invoice, null, $asksPayment);

        return $captured;
    }

    /**
     * WG12: Was vor der Rechnung schon bezahlt ist — ein Gutschein beim Kauf,
     * eine Teilzahlung vor dem Versand —, steht im Schnappschuss, und Giro-Code
     * und Zahlungsziel verlangen nur den Rest. Bis dahin forderten beide den
     * ganzen Betrag.
     */
    public function testNachEinerTeilzahlungVerlangtDieRechnungNurDenRest(): void
    {
        $due = [];
        $payload = $this->issueAfterPayments([['method' => 'Gutschein', 'amount' => '3.9000']], DocumentType::Invoice, $due);

        self::assertSame([['method' => 'Gutschein', 'amount' => '3.9000']], $payload['payment']['payments']);
        self::assertSame('10.0000', $payload['payment']['outstanding']);
        self::assertStringContainsString("\nEUR10.00\n", (string) $payload['payment']['giroCode']);
        self::assertArrayHasKey('dueDate', $payload['payment']);
        self::assertGreaterThan(0, $due[0] ?? 0);
    }

    public function testEineBezahlteRechnungVerlangtNichts(): void
    {
        $due = [];
        $payload = $this->issueAfterPayments([['method' => 'Gutschein', 'amount' => '10.0000'], ['method' => 'Vorkasse', 'amount' => '3.9000']], DocumentType::Invoice, $due);

        self::assertSame('0.0000', $payload['payment']['outstanding']);
        self::assertArrayNotHasKey('giroCode', $payload['payment']);
        self::assertArrayNotHasKey('dueDate', $payload['payment']);
        // Das Ziel einer früheren Rechnung darf nicht stehen bleiben.
        self::assertSame([0], $due);
    }

    /**
     * Eine Erstattung zählt mit: was zurückging, ist wieder offen.
     */
    public function testEineErstattungMindertDasBezahlte(): void
    {
        $due = [];
        $payload = $this->issueAfterPayments([['method' => 'Vorkasse', 'amount' => '13.9000'], ['method' => 'refund', 'amount' => '-3.9000']], DocumentType::Invoice, $due);

        self::assertSame('3.9000', $payload['payment']['outstanding']);
        self::assertStringContainsString("\nEUR3.90\n", (string) $payload['payment']['giroCode']);
    }

    /**
     * Ein Storno verlangt keine Zahlung — es nennt auch keine.
     */
    public function testDasStornoNenntKeineZahlungen(): void
    {
        $due = [];
        $payload = $this->issueAfterPayments([['method' => 'Gutschein', 'amount' => '3.9000']], DocumentType::Cancellation, $due);

        self::assertArrayNotHasKey('payments', $payload['payment']);
        self::assertArrayNotHasKey('outstanding', $payload['payment']);
        self::assertSame([], $due);
    }

    /**
     * @param list<array{method: string, amount: string}> $payments
     * @param list<int>                                   $due
     *
     * @return array<string, mixed>
     */
    private function issueAfterPayments(array $payments, DocumentType $type, array &$due): array
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn(['orderNumber' => 'WEB-1', 'currency' => 'EUR', 'grandTotal' => '13.9000']);
        $repository->method('findOrderItemRows')->willReturn([]);

        $captured = [];
        $repository->method('insert')->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $i, int $r, array $payload) use (&$captured): int {
            $captured = $payload;

            return 1;
        });
        $repository->method('recordPaymentDue')->willReturnCallback(static function (int $orderId, int $dueAt) use (&$due): void {
            $due[] = $dueAt;
        });

        $source = $this->createStub(DocumentSourceInterface::class);
        $source->method('contextFor')->willReturn([
            'issuer' => [], 'logo' => null,
            'payment' => ['instructions' => '', 'details' => ['accountHolder' => 'Muster GmbH', 'iban' => 'AT611904300234573201'], 'giroCode' => true, 'dueDays' => 14],
            'notes' => ['footer' => '', 'reverseCharge' => ''],
            'payments' => $payments,
        ]);

        $allocator = $this->createStub(NumberAllocatorInterface::class);
        $allocator->method('next')->willReturn('RE-00015');

        (new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $source))->issue(1, 3, $type, DocumentType::Cancellation === $type ? 7 : null);

        return $captured;
    }

    /**
     * @param array<string, string> $bank
     *
     * @return array<string, mixed>
     */
    private function issueWith(DocumentType $type, bool $giroCode, array $bank, bool $allowGiroCode = true): array
    {
        $repository = $this->createMock(DocumentRepositoryInterface::class);
        $repository->method('findOrderRow')->willReturn(['orderNumber' => 'WEB-1', 'currency' => 'EUR', 'grandTotal' => '13.9000']);
        $repository->method('findOrderItemRows')->willReturn([]);

        $captured = [];
        $repository->method('insert')->willReturnCallback(static function (int $o, int $s, DocumentType $t, string $n, int $i, int $r, array $payload) use (&$captured): int {
            $captured = $payload;

            return 1;
        });

        $source = $this->createStub(DocumentSourceInterface::class);
        $source->method('contextFor')->willReturn([
            'issuer' => [], 'logo' => null, 'payment' => ['instructions' => '', 'details' => $bank, 'giroCode' => $giroCode],
            'notes' => ['footer' => '', 'reverseCharge' => ''],
        ]);

        $allocator = $this->createStub(NumberAllocatorInterface::class);
        $allocator->method('next')->willReturn('RE-00013');

        (new DocumentIssuer($repository, $allocator, new DocumentPayloadBuilder(), $source))->issueOnce(1, 3, $type, DocumentType::Cancellation === $type ? 7 : null, $allowGiroCode);

        return $captured;
    }
}

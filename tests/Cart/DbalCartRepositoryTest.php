<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;

class DbalCartRepositoryTest extends TestCase
{
    use CartSchema;

    private DbalCartRepository $repository;

    protected function setUp(): void
    {
        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCartTables();
        $this->repository = new DbalCartRepository($this->cartConnection);
    }

    public function testEinGastWarenkorbWirdUeberDenHashGefunden(): void
    {
        $id = $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 3, 'state' => 'open']);

        $cart = $this->repository->findByToken('abc', 3);

        self::assertNotNull($cart);
        self::assertSame($id, $cart->id);
        self::assertSame(3, $cart->shopId);
    }

    public function testEinUmgewandelterWarenkorbWirdNichtMehrGefunden(): void
    {
        // Nach dem Kauf ist er kein Warenkorb mehr, sondern ein Beleg dafür,
        // dass dieser Token schon gekauft hat (C-7 braucht ihn noch).
        $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 3, 'state' => 'converted']);

        self::assertNull($this->repository->findByToken('abc', 3));
    }

    /**
     * 🔴 `lockForPurchase()` lief bis zum 2026-09-23 in KEINEM Test.
     *
     * Der Name stand in Mocks, der SQL-String nie in einer Datenbank: SQLite
     * kennt kein `FOR UPDATE`, also ist die Methode auf dem Testweg
     * unerreichbar. Ein Tippfehler im Alias `AS orderId` oder im Quoting wäre
     * durch 2504 grüne Tests gegangen und beim ersten Klick auf c5
     * aufgeschlagen (F10 des Fable-Reviews).
     *
     * Geprüft wird deshalb, was an die Datenbank GEHT — gegen eine gemockte
     * Verbindung, wie NumberRangeAllocatorTest es für seine Sperre tut.
     */
    public function testDieSperreFragtNachStatusUndBestellungUndSperrtDieZeile(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $n): string => '`'.$n.'`');

        $gesehen = '';
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql) use (&$gesehen): array {
                $gesehen = $sql;

                return ['state' => 'open', 'orderId' => 0];
            },
        );

        $cart = (new DbalCartRepository($connection))->lockForPurchase(12);

        self::assertSame(['state' => 'open', 'order' => 0], $cart);

        // Die Sperre selbst — ohne sie wartet der zweite Klick nicht, und aus
        // einem Doppelklick werden zwei Bestellungen.
        self::assertStringContainsString('FOR UPDATE', $gesehen);

        // Und der reservierte Spaltenname, gequotet und auf einen Alias
        // gelegt: `order` roh im SELECT ist ein Syntaxfehler, und der Alias
        // ist das, was die Methode auslesen will.
        self::assertStringContainsString('`order` AS orderId', $gesehen);
    }

    public function testEinVerschwundenerWarenkorbMeldetSichAlsNull(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('quoteIdentifier')->willReturn('`order`');
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull((new DbalCartRepository($connection))->lockForPurchase(12));
    }

    public function testDerKaufNimmtDemWarenkorbSeinenTokenUndMachtIhnZurBestellung(): void
    {
        $id = $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 3, 'state' => 'open']);

        $this->repository->markConverted($id, 77, 1_757_000_000);

        $row = $this->cartConnection->fetchAssociative(
            'SELECT state, "order" AS orderId, tokenHash FROM tl_consho_cart WHERE id = ?',
            [$id],
        );

        self::assertIsArray($row);
        self::assertSame('converted', $row['state']);
        self::assertSame(77, (int) $row['orderId']);

        /*
         * Der Kern der Sache (offene Punkte, für C-7 entschieden): Der
         * eindeutige Schlüssel läuft über (tokenHash, shop) und kennt kein
         * `state`. Bliebe der Hash stehen, liefe der nächste Klick desselben
         * Cookies im selben Shop in create() und damit in eine
         * UniqueConstraintViolationException — HTTP 500 statt 303.
         */
        self::assertNull($row['tokenHash']);
        self::assertNull($this->repository->findByToken('abc', 3));

        // Und der Beleg dafür: Derselbe Token darf im selben Shop wieder einen
        // Warenkorb anlegen. Genau das ging vorher nicht.
        self::assertGreaterThan(0, $this->repository->create(CartToken::hash('abc'), 0, 3, 1_757_000_100));
    }

    public function testEinGastWarenkorbWirdNurImEigenenShopGefunden(): void
    {
        // Ein Cookie, zwei Shops: derselbe Token trägt je Shop einen Korb.
        // Ohne den Shop in der Bedingung bekäme Shop 4 den Korb von Shop 3 —
        // und der Besucher legte einen Artikel in einen fremden Warenkorb.
        $inShopThree = $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 3, 'state' => 'open']);
        $inShopFour = $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 4, 'state' => 'open']);

        self::assertSame($inShopThree, $this->repository->findByToken('abc', 3)?->id);
        self::assertSame($inShopFour, $this->repository->findByToken('abc', 4)?->id);

        // Ein dritter Shop hat noch keinen — nicht etwa den erstbesten.
        self::assertNull($this->repository->findByToken('abc', 5));
    }

    public function testDerWarenkorbEinesMitgliedsWirdUeberDieIdGefunden(): void
    {
        $id = $this->insertCart(['member' => 5, 'shop' => 3, 'state' => 'open']);

        self::assertSame($id, $this->repository->findForMember(5, 3)?->id);
        self::assertNull($this->repository->findForMember(6, 3));
    }

    public function testDerWarenkorbEinesMitgliedsWirdNurImEigenenShopGefunden(): void
    {
        // Mitglieder sind zentral und gelten für alle Shops (Entscheidung vom
        // 2026-09-21), ihre Warenkörbe nicht: Ein Mitglied bei zwei
        // rechnungsstellenden Einheiten hat bei jeder einen eigenen.
        $inShopThree = $this->insertCart(['member' => 5, 'shop' => 3, 'state' => 'open']);
        $inShopFour = $this->insertCart(['member' => 5, 'shop' => 4, 'state' => 'open']);

        self::assertSame($inShopThree, $this->repository->findForMember(5, 3)?->id);
        self::assertSame($inShopFour, $this->repository->findForMember(5, 4)?->id);
        self::assertNull($this->repository->findForMember(5, 5));
    }

    public function testAnlegenSetztBeideZeitstempel(): void
    {
        $id = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $row = $this->cartConnection->fetchAssociative('SELECT * FROM tl_consho_cart WHERE id = ?', [$id]);

        self::assertSame(1_000, (int) $row['createdAt']);
        self::assertSame(1_000, (int) $row['updatedAt']);
        self::assertSame(1_000, (int) $row['tstamp']);
        self::assertSame('open', $row['state']);
    }

    public function testDerselbeArtikelAddiertDieMenge(): void
    {
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $this->repository->addItem($cartId, 7, '2.0000', 1_000);
        $this->repository->addItem($cartId, 7, '3.0000', 2_000);

        $rows = $this->cartConnection->fetchAllAssociative('SELECT product, quantity FROM tl_consho_cart_item');

        self::assertCount(1, $rows);
        // Numerisch, nicht als Zeichenkette: SQLite liefert aus einer
        // NUMERIC-Spalte 5 statt '5.0000' (siehe Befunde im Kopf).
        self::assertSame(5.0, (float) $rows[0]['quantity']);
    }

    public function testJedesModellIstEineEigeneZeile(): void
    {
        // Abo-2, P9: dasselbe Modell zweimal addiert die Lizenzen, ein anderes
        // Modell desselben Artikels ist eine eigene Zeile.
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $this->repository->addItem($cartId, 7, '1.0000', 1_000, 5);
        $this->repository->addItem($cartId, 7, '2.0000', 1_000, 5);
        $this->repository->addItem($cartId, 7, '1.0000', 2_000);

        $items = $this->repository->itemsOf($cartId);

        self::assertCount(2, $items);
        self::assertSame([5, 0], array_map(static fn ($item) => $item->subscriptionRule, $items));
        self::assertSame([3.0, 1.0], array_map(static fn ($item) => (float) $item->quantity, $items));
    }

    public function testDieUebernahmeBehaeltDasModell(): void
    {
        // Ein Gast legt das Monatsabo ab, das Mitglied hat denselben Artikel
        // als Einmalkauf — nach dem Anmelden sind es zwei Zeilen, nicht eine.
        $guestId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->addItem($guestId, 7, '1.0000', 1_000, 5);
        $memberCartId = $this->repository->create(null, 42, 3, 1_000);
        $this->repository->addItem($memberCartId, 7, '1.0000', 1_000);

        $guest = $this->repository->findByToken('xyz', 3);
        self::assertNotNull($guest);
        $this->repository->takeOver($guest, 42, 2_000);

        self::assertSame([0, 5], array_map(static fn ($item) => $item->subscriptionRule, $this->repository->itemsOf($memberCartId)));
    }

    public function testEineVerlaengerungIstEineEigeneZeileUndAddiertNie(): void
    {
        // Abo-7b (P67): die Menge des alten Abos, fest. Ein zweiter Klick legt
        // nichts dazu, und das gewöhnliche Hinzufügen desselben Modells landet
        // in einer eigenen Zeile, nicht auf der Verlängerung.
        $cartId = $this->repository->create(null, 42, 3, 1_000);

        $this->repository->addRenewal($cartId, 7, '2.0000', 5, 31, 1_000);
        $this->repository->addRenewal($cartId, 7, '2.0000', 5, 31, 1_500);
        $this->repository->addItem($cartId, 7, '1.0000', 2_000, 5);
        $this->repository->addItem($cartId, 7, '1.0000', 2_000, 5);

        $items = $this->repository->itemsOf($cartId);

        self::assertCount(2, $items);
        self::assertSame([31, 0], array_map(static fn ($item) => $item->renews, $items));
        self::assertSame([2.0, 2.0], array_map(static fn ($item) => (float) $item->quantity, $items));
        self::assertSame([5, 5], array_map(static fn ($item) => $item->subscriptionRule, $items));
    }

    public function testZweiVerlaengerungenDesselbenArtikelsSindZweiZeilen(): void
    {
        $cartId = $this->repository->create(null, 42, 3, 1_000);

        $this->repository->addRenewal($cartId, 7, '1.0000', 5, 31, 1_000);
        $this->repository->addRenewal($cartId, 7, '3.0000', 5, 32, 1_000);

        self::assertSame([31, 32], array_map(static fn ($item) => $item->renews, $this->repository->itemsOf($cartId)));
    }

    public function testDieUebernahmeBehaeltDieVerlaengerung(): void
    {
        $guestId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->addRenewal($guestId, 7, '2.0000', 5, 31, 1_000);
        $memberCartId = $this->repository->create(null, 42, 3, 1_000);
        $this->repository->addItem($memberCartId, 7, '1.0000', 1_000, 5);

        $guest = $this->repository->findByToken('xyz', 3);
        self::assertNotNull($guest);
        $this->repository->takeOver($guest, 42, 2_000);

        $items = $this->repository->itemsOf($memberCartId);
        self::assertSame([0, 31], array_map(static fn ($item) => $item->renews, $items));
        self::assertSame([1.0, 2.0], array_map(static fn ($item) => (float) $item->quantity, $items));
    }

    /**
     * WG-5: Gutscheine sind Geld — die Anmeldung nimmt die als Gast
     * eingegebenen mit, hinter die eigenen, ohne Doppelte.
     */
    public function testDieUebernahmeBehaeltDieGutscheine(): void
    {
        $guestId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->setVouchers($guestId, ['BBBBBBBBBBBBBBBB', 'AAAAAAAAAAAAAAAA'], 1_000);
        $memberCartId = $this->repository->create(null, 42, 3, 1_000);
        $this->repository->setVouchers($memberCartId, ['AAAAAAAAAAAAAAAA'], 1_000);

        $guest = $this->repository->findByToken('xyz', 3);
        self::assertNotNull($guest);
        $this->repository->takeOver($guest, 42, 2_000);

        self::assertSame(['AAAAAAAAAAAAAAAA', 'BBBBBBBBBBBBBBBB'], $this->repository->vouchersOf($memberCartId));
    }

    public function testDieDatenbankAddiertDieMengeSelbst(): void
    {
        // Kein Lesen-dann-Schreiben: die zweite Ablage darf die vorhandene
        // Menge nicht erst nach PHP holen, dort addieren und zurückschreiben —
        // zwei gleichzeitige Aufrufe läsen sonst denselben Stand und der
        // zweite überschriebe den ersten.
        //
        // Belegt wird das an den abgesetzten Anweisungen. Echte Parallelität
        // ist gegen SQLite im Arbeitsspeicher nicht herstellbar; prüfbar ist,
        // dass es kein Fenster zwischen Lesen und Schreiben mehr GIBT.
        $log = $this->mitAufzeichnung();
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->addItem($cartId, 7, '2.0000', 1_000);
        $log->zuruecksetzen();

        $this->repository->addItem($cartId, 7, '3.0000', 2_000);

        $aufDerPosition = $log->anweisungenMit('tl_consho_cart_item');

        self::assertCount(1, $aufDerPosition, 'Eine einzige Anweisung auf der Position, kein SELECT davor');
        self::assertStringContainsString('quantity = quantity +', $aufDerPosition[0]);
        self::assertSame(5.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
    }

    public function testEinDazwischengekommenerFremderEintragWirdZurAddition(): void
    {
        // Der zweite gleichzeitige Aufruf, an der einzigen Stelle simuliert,
        // an der er noch wehtun kann: zwischen unserem UPDATE (das nichts
        // findet) und unserem INSERT legt ein anderer Request die Zeile an.
        // Der Unique-Index auf (pid, product) weist unser INSERT dann ab — das
        // darf kein Serverfehler werden, sondern muss zur Addition werden.
        $log = $this->mitAufzeichnung();
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $log->vorDemEinfuegen = function () use ($cartId): void {
            $this->cartConnection->insert('tl_consho_cart_item', [
                'pid' => $cartId,
                'tstamp' => 1_500,
                'product' => 7,
                'quantity' => '4.0000',
                'addedAt' => 1_500,
            ]);
        };

        self::assertTrue($this->repository->addItem($cartId, 7, '3.0000', 2_000));

        $rows = $this->cartConnection->fetchAllAssociative('SELECT quantity FROM tl_consho_cart_item');

        self::assertCount(1, $rows);
        self::assertSame(7.0, (float) $rows[0]['quantity'], 'Die 4 des anderen Requests plus unsere 3');
    }

    public function testEineZwischenzeitlichAngelegteZeileWirdZurAdditionStattZurAblehnung(): void
    {
        // Die andere Verschränkung derselben zwei Anfragen (Befund 2): Der
        // fremde Request legt die Zeile NACH unserem UPDATE und VOR unserem
        // hasLine() an. hasLine() sagt dann „da ist eine Zeile" — was ohne
        // zweiten Versuch als Deckelung gelesen würde. Der Besucher verlöre
        // seine Menge und bekäme obendrein „Diese Menge ist nicht möglich"
        // für eine einwandfreie Menge.
        $log = $this->mitAufzeichnung();
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $log->vorDemNachsehen = function () use ($cartId): void {
            $this->cartConnection->insert('tl_consho_cart_item', [
                'pid' => $cartId,
                'tstamp' => 1_500,
                'product' => 7,
                'quantity' => '4.0000',
                'addedAt' => 1_500,
            ]);
        };

        self::assertTrue($this->repository->addItem($cartId, 7, '3.0000', 2_000));

        $rows = $this->cartConnection->fetchAllAssociative('SELECT quantity FROM tl_consho_cart_item');

        self::assertCount(1, $rows);
        self::assertSame(7.0, (float) $rows[0]['quantity'], 'Die 4 des anderen Requests plus unsere 3');

        // Ein zweiter Versuch, keine Schleife: genau zwei Additions-UPDATEs.
        self::assertCount(2, $log->anweisungenMit('quantity = quantity +'));

        // Und geschrieben heißt auch: die Aufbewahrungsfrist läuft neu.
        self::assertSame(2_000, (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testDieDeckelungBleibtEineAblehnungAuchNachDemZweitenVersuch(): void
    {
        // Die Gegenprobe zum Test darüber: Wenn wirklich die Deckelung im
        // WHERE zugeschlagen hat, scheitert auch der zweite Versuch — und
        // genau dann ist die Ablehnung richtig. Ohne diese Zusicherung wäre
        // „einfach noch einmal versuchen" nicht von „immer weiter versuchen"
        // zu unterscheiden.
        $log = $this->mitAufzeichnung();
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        self::assertTrue($this->repository->addItem($cartId, 7, '999999.0000', 1_000));
        $log->zuruecksetzen();

        self::assertFalse($this->repository->addItem($cartId, 7, '1.0000', 2_000));

        self::assertCount(2, $log->anweisungenMit('quantity = quantity +'));
        self::assertSame(999999.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
        self::assertSame(1_000, (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testDieSummeUeberDerSpaltengrenzeWirdAbgelehnt(): void
    {
        // Quantity::normalise() weist eine EINZELNE Menge über 999999 ab,
        // damit decimal(10,4) sie nicht stillschweigend kappt. Mehrere für
        // sich gültige Additionen dürfen dieselbe Grenze nicht umgehen.
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        self::assertTrue($this->repository->addItem($cartId, 7, '999999.0000', 1_000));
        self::assertFalse($this->repository->addItem($cartId, 7, '1.0000', 2_000));

        self::assertSame(999999.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
        // Abgelehnt heißt: nichts ist passiert. Auch die Aufbewahrungsfrist
        // darf eine folgenlose Ablage nicht verlängern.
        self::assertSame(1_000, (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testHinzufuegenSchiebtUpdatedAtVor(): void
    {
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $this->repository->addItem($cartId, 7, '1.0000', 2_500);

        self::assertSame(2_500, (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testMengeSetzenUndEntfernen(): void
    {
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->addItem($cartId, 7, '1.0000', 1_000);
        $itemId = (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item');

        $this->repository->setQuantity($cartId, $itemId, '4.0000', 2_000);
        self::assertSame(4.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));

        $this->repository->removeItem($cartId, $itemId, 3_000);
        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }

    /**
     * Spec C, offene Punkte (C-5, Punkt 3): Wer den Warenkorb leert, glaubt
     * aufgeräumt zu haben — die Adresse aus der Kasse geht mit der letzten
     * Zeile. Gegenprobe: solange eine Zeile bleibt, bleibt auch sie.
     */
    public function testDieLetzteZeileNimmtDieCheckoutDatenMit(): void
    {
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->addItem($cartId, 7, '1.0000', 1_000);
        $this->repository->addItem($cartId, 8, '1.0000', 1_000);
        [$first, $second] = array_map('intval', $this->cartConnection->fetchFirstColumn('SELECT id FROM tl_consho_cart_item ORDER BY id'));
        $this->repository->saveCheckoutData($cartId, (new CheckoutData())->withBilling(new Address(firstname: 'Eva', country: 'AT')), 1_500);

        $this->repository->removeItem($cartId, $first, 2_000);
        self::assertSame('Eva', $this->repository->checkoutDataOf($cartId)->billing->firstname, 'eine Zeile bleibt');

        $this->repository->removeItem($cartId, $second, 3_000);
        self::assertNull($this->cartConnection->fetchOne('SELECT checkoutData FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testEinePositionAusEinemFremdenWarenkorbBleibtUnberuehrt(): void
    {
        // Sonst räumt eine erratene Positions-Id fremde Warenkörbe leer.
        $mine = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $other = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);
        $this->repository->addItem($other, 7, '1.0000', 1_000);
        $itemId = (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item');

        $this->repository->removeItem($mine, $itemId, 2_000);

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }

    public function testEineMengeInEinemFremdenWarenkorbBleibtUnberuehrt(): void
    {
        // Schwester zu testEinePositionAusEinemFremdenWarenkorbBleibtUnberuehrt:
        // auch setQuantity() nimmt Warenkorb- UND Positions-Id, sonst schreibt
        // eine erratene Positions-Id in fremde Warenkörbe.
        $mine = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $other = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);
        $this->repository->addItem($other, 7, '1.0000', 1_000);
        $itemId = (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item');

        $this->repository->setQuantity($mine, $itemId, '9.0000', 2_000);

        self::assertSame(1.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
    }

    public function testDieNotizWirdGenauEinmalGelesen(): void
    {
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->setNotice($cartId, CartNotice::Added);

        self::assertSame(CartNotice::Added, $this->repository->takeNotice($cartId));
        self::assertNull($this->repository->takeNotice($cartId));
    }

    public function testDieNotizVerlaengertDieAufbewahrungNicht(): void
    {
        // Die Notiz ist Anzeigezustand, keine Benutzung des Warenkorbs. Würde
        // sie updatedAt vorschieben, hielte ein vergessener Tab, der die
        // Warenkorbseite offen hat, die Personendaten beliebig lange am Leben.
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $this->repository->setNotice($cartId, CartNotice::Added);
        $this->repository->takeNotice($cartId);

        self::assertSame(1_000, (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testEineUnbekannteNotizGiltAlsKeine(): void
    {
        // Ein Wert aus einer älteren Version darf keine Ausnahme werfen.
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->cartConnection->update('tl_consho_cart', ['notice' => 'was_auch_immer'], ['id' => $cartId]);

        self::assertNull($this->repository->takeNotice($cartId));
    }

    public function testDiePositionenKommenInDerReihenfolgeDesHinzufuegens(): void
    {
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $this->repository->addItem($cartId, 9, '1.0000', 2_000);
        $this->repository->addItem($cartId, 7, '1.0000', 1_000);

        self::assertSame([7, 9], array_map(static fn ($item) => $item->articleId, $this->repository->itemsOf($cartId)));
    }

    public function testDerArtikelEinerPositionWirdNurImEigenenWarenkorbGefunden(): void
    {
        // Dritte Schwester zu den beiden Tests weiter oben: Auch das LESEN des
        // Artikels nimmt das Id-Paar. Sonst prüfte das Warenkorb-Element eine
        // fremde Position und schriebe die Menge anschließend ins Leere — oder,
        // schlimmer, die Notiz auf den eigenen Warenkorb, als wäre etwas
        // geschehen.
        $mine = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $other = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);
        $this->repository->addItem($other, 7, '1.0000', 1_000);
        $itemId = (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item');

        self::assertSame(7, $this->repository->articleOf($other, $itemId));
        self::assertSame(0, $this->repository->articleOf($mine, $itemId));
        self::assertSame(0, $this->repository->articleOf($other, $itemId + 100));
    }

    public function testCheckoutDatenGehenDurchDieDatenbankUndKommenGleichZurueck(): void
    {
        $id = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);

        $this->repository->saveCheckoutData(
            $id,
            (new CheckoutData())
                ->withBilling(new Address(firstname: 'Eva', street: 'Hauptstr. 1', country: 'AT'))
                ->withPaymentMethod(7),
            2_000,
        );

        $back = $this->repository->checkoutDataOf($id);

        self::assertSame('Eva', $back->billing->firstname);
        self::assertSame('AT', $back->deliveryCountry());
        self::assertSame(7, $back->paymentMethod);
    }

    public function testEinWarenkorbOhneCheckoutDatenLiestSichAlsLeerUndNichtAlsFehler(): void
    {
        // Die Spalte ist seit C-3 da und war bis C-5 immer NULL.
        $id = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);

        self::assertEquals(new CheckoutData(), $this->repository->checkoutDataOf($id));
        self::assertEquals(new CheckoutData(), $this->repository->checkoutDataOf($id + 100));
    }

    /**
     * Eine Adresse einzugeben ist eine Handlung. Zählte sie nicht, könnte der
     * Aufräum-Cron einem langen Checkout den Warenkorb unter den Händen
     * wegräumen — 30 Tage sind lang, aber der Zähler stünde still.
     */
    public function testDasSpeichernDerCheckoutDatenSetztDenZeitstempelNeu(): void
    {
        $id = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);

        $this->repository->saveCheckoutData($id, new CheckoutData(), 5_000);

        self::assertSame(
            5_000,
            (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$id]),
        );
        self::assertSame(
            5_000,
            (int) $this->cartConnection->fetchOne('SELECT checkoutDataAt FROM tl_consho_cart WHERE id = ?', [$id]),
            'die eigene Uhr der Kassendaten (E1)',
        );
    }

    /**
     * Baut Verbindung und Repository neu auf, diesmal mit der Logging-
     * Middleware von DBAL dazwischen. Das Schema-Anlegen wird nicht
     * mitgezählt — der Mitschnitt beginnt leer.
     */
    private function mitAufzeichnung(): CartStatementLog
    {
        $log = new CartStatementLog();

        $configuration = new Configuration();
        $configuration->setMiddlewares([new LoggingMiddleware($log)]);

        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->createCartTables();
        $this->repository = new DbalCartRepository($this->cartConnection);
        $log->zuruecksetzen();

        return $log;
    }
}

/**
 * Hängt sich per Logging-Middleware zwischen DBAL und die Datenbank: schreibt
 * jede abgesetzte Anweisung mit und kann genau einmal dazwischenfunken, bevor
 * eine Position eingefügt wird.
 *
 * Das Dazwischenfunken ist der Platzhalter für einen zweiten, gleichzeitigen
 * Request. Ein SQLite-Trigger könnte es nicht: SQLite macht bei einem
 * Constraint-Fehler die GANZE Anweisung rückgängig, also auch das, was der
 * Trigger geschrieben hat — die fremde Zeile wäre danach wieder weg. Hier
 * läuft der fremde INSERT als eigene, abgeschlossene Anweisung, bevor unserer
 * überhaupt startet.
 */
final class CartStatementLog extends AbstractLogger
{
    /**
     * @var list<string>
     */
    private array $statements = [];

    /**
     * @var (\Closure(): void)|null
     */
    public \Closure|null $vorDemEinfuegen = null;

    /**
     * Derselbe Haken eine Anweisung früher: vor dem hasLine()-SELECT, also
     * nachdem das erste UPDATE schon nichts gefunden hat. Das ist die zweite
     * mögliche Verschränkung zweier gleichzeitiger Aufrufe (Befund 2).
     *
     * @var (\Closure(): void)|null
     */
    public \Closure|null $vorDemNachsehen = null;

    private bool $imHaken = false;

    /**
     * @param mixed   $level
     * @param mixed[] $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $sql = $context['sql'] ?? null;

        if ($this->imHaken || !\is_string($sql)) {
            return;
        }

        $this->statements[] = $sql;

        $haken = match (true) {
            null !== $this->vorDemEinfuegen && str_contains($sql, 'INSERT INTO tl_consho_cart_item') => $this->vorDemEinfuegen,
            null !== $this->vorDemNachsehen && str_contains($sql, 'SELECT 1 FROM tl_consho_cart_item') => $this->vorDemNachsehen,
            default => null,
        };

        if (null === $haken) {
            return;
        }

        // Genau einmal, sonst funkt der Haken in den Wiederholungsversuch.
        $this->vorDemEinfuegen = null;
        $this->vorDemNachsehen = null;
        $this->imHaken = true;

        try {
            $haken();
        } finally {
            $this->imHaken = false;
        }
    }

    public function zuruecksetzen(): void
    {
        $this->statements = [];
    }

    /**
     * Alle mitgeschnittenen Anweisungen, die den Ausdruck enthalten.
     *
     * @return list<string>
     */
    public function anweisungenMit(string $ausdruck): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $sql) => str_contains($sql, $ausdruck),
        ));
    }
}

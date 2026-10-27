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
        // After the purchase it is no longer a cart but proof
        // that this token has already bought (C-7 still needs it).
        $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 3, 'state' => 'converted']);

        self::assertNull($this->repository->findByToken('abc', 3));
    }

    /**
     * 🔴 `lockForPurchase()` used to run in NO test.
     *
     * The name appeared in mocks, the SQL string never in a database: SQLite
     * does not know `FOR UPDATE`, so the method cannot be reached on the test
     * path. A typo in the alias `AS orderId` or in the quoting would have
     * passed every green test and surfaced on the first click on a real
     * installation.
     *
     * What is checked is therefore what GOES to the database — against a mocked
     * connection, as NumberRangeAllocatorTest does for its lock.
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

        // The lock itself — without it the second click does not wait, and
        // a double click turns into two orders.
        self::assertStringContainsString('FOR UPDATE', $gesehen);

        // And the reserved column name, quoted and mapped to an alias:
        // a raw `order` in the SELECT is a syntax error, and the alias
        // is what the method wants to read.
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
         * The heart of the matter (open points, decided for C-7): the
         * unique key covers (tokenHash, shop) and knows no
         * `state`. If the hash stayed, the next click of the same
         * cookie in the same shop would run into create() and thus into a
         * UniqueConstraintViolationException — HTTP 500 instead of 303.
         */
        self::assertNull($row['tokenHash']);
        self::assertNull($this->repository->findByToken('abc', 3));

        // And the proof of it: the same token may create a cart again in the
        // same shop. Exactly that did not work before.
        self::assertGreaterThan(0, $this->repository->create(CartToken::hash('abc'), 0, 3, 1_757_000_100));
    }

    public function testEinGastWarenkorbWirdNurImEigenenShopGefunden(): void
    {
        // One cookie, two shops: the same token carries one cart per shop.
        // Without the shop in the condition, shop 4 would get the cart of shop 3 —
        // and the visitor would put an article into someone else's cart.
        $inShopThree = $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 3, 'state' => 'open']);
        $inShopFour = $this->insertCart(['tokenHash' => CartToken::hash('abc'), 'shop' => 4, 'state' => 'open']);

        self::assertSame($inShopThree, $this->repository->findByToken('abc', 3)?->id);
        self::assertSame($inShopFour, $this->repository->findByToken('abc', 4)?->id);

        // A third shop has none yet — and certainly not the first one at hand.
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
        // Members are central and apply to all shops, their carts do not:
        // a member with two invoicing entities has a cart of their own
        // with each.
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
        // Numerically, not as a string: SQLite returns 5 instead of '5.0000'
        // from a NUMERIC column.
        self::assertSame(5.0, (float) $rows[0]['quantity']);
    }

    public function testJedesModellIstEineEigeneZeile(): void
    {
        // Abo-2, P9: the same model twice adds up the licences, another
        // model of the same article is a line of its own.
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
        // A guest puts down the monthly subscription, the member has the same article
        // as a one-off purchase — after logging in there are two lines, not one.
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
        // Abo-7b (P67): the quantity of the old subscription, fixed. A second click adds
        // nothing, and the ordinary adding of the same model ends up
        // in a line of its own, not on the renewal.
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
     * WG-5: vouchers are money — logging in takes along those entered as a
     * guest, behind the member's own, without duplicates.
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
        // No read-then-write: the second add must not first fetch the existing
        // quantity into PHP, add there and write it back —
        // two simultaneous calls would otherwise read the same state and the
        // second would overwrite the first.
        //
        // This is proven by the statements sent. Real concurrency cannot be
        // produced against in-memory SQLite; what can be checked is
        // that there no longer IS a window between reading and writing.
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
        // The second simultaneous call, simulated at the only place
        // where it can still hurt: between our UPDATE (which finds
        // nothing) and our INSERT another request creates the row.
        // The unique index on (pid, product) then rejects our INSERT — that
        // must not become a server error but must turn into the addition.
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
        // The other interleaving of the same two requests: the
        // other request creates the row AFTER our UPDATE and BEFORE our
        // hasLine(). hasLine() then says "there is a row" — which without a
        // second attempt would be read as the cap. The visitor would lose
        // their quantity and on top of that get "This quantity is not possible"
        // for a perfectly fine quantity.
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

        // One second attempt, not a loop: exactly two addition UPDATEs.
        self::assertCount(2, $log->anweisungenMit('quantity = quantity +'));

        // And written also means: the retention period starts anew.
        self::assertSame(2_000, (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testDieDeckelungBleibtEineAblehnungAuchNachDemZweitenVersuch(): void
    {
        // The counter-check to the test above: if the cap in the
        // WHERE really kicked in, the second attempt fails too — and
        // exactly then the refusal is right. Without this assertion,
        // "just try once more" could not be told apart from "keep trying
        // forever".
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
        // Quantity::normalise() rejects a SINGLE quantity above 999999
        // so that decimal(10,4) does not silently cap it. Several additions,
        // each valid on its own, must not get around the same limit.
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        self::assertTrue($this->repository->addItem($cartId, 7, '999999.0000', 1_000));
        self::assertFalse($this->repository->addItem($cartId, 7, '1.0000', 2_000));

        self::assertSame(999999.0, (float) $this->cartConnection->fetchOne('SELECT quantity FROM tl_consho_cart_item'));
        // Refused means: nothing happened. The retention period
        // must not be extended by an add without effect either.
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
     * Spec C, open points (C-5): whoever empties the cart believes they have
     * cleaned up — the address from the checkout goes with the last
     * line. Counter-check: as long as a line remains, the address remains too.
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
        // Otherwise a guessed line item id empties other people's carts.
        $mine = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);
        $other = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);
        $this->repository->addItem($other, 7, '1.0000', 1_000);
        $itemId = (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart_item');

        $this->repository->removeItem($mine, $itemId, 2_000);

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }

    public function testEineMengeInEinemFremdenWarenkorbBleibtUnberuehrt(): void
    {
        // Sister to testEinePositionAusEinemFremdenWarenkorbBleibtUnberuehrt:
        // setQuantity() also takes cart AND line item id, otherwise
        // a guessed line item id writes into other people's carts.
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
        // The notice is display state, not use of the cart. If it
        // pushed updatedAt forward, a forgotten tab that keeps the
        // cart page open would keep the personal data alive indefinitely.
        $cartId = $this->repository->create(CartToken::hash('xyz'), 0, 3, 1_000);

        $this->repository->setNotice($cartId, CartNotice::Added);
        $this->repository->takeNotice($cartId);

        self::assertSame(1_000, (int) $this->cartConnection->fetchOne('SELECT updatedAt FROM tl_consho_cart WHERE id = ?', [$cartId]));
    }

    public function testEineUnbekannteNotizGiltAlsKeine(): void
    {
        // A value from an older version must not throw an exception.
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
        // Third sister to the two tests further up: READING the
        // article also takes the id pair. Otherwise the cart element would check
        // someone else's line item and then write the quantity into the void — or,
        // worse, the notice onto its own cart, as if something had
        // happened.
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
        // The column has existed since C-3 and was always NULL until C-5.
        $id = $this->repository->create(CartToken::hash('abc'), 0, 3, 1_000);

        self::assertEquals(new CheckoutData(), $this->repository->checkoutDataOf($id));
        self::assertEquals(new CheckoutData(), $this->repository->checkoutDataOf($id + 100));
    }

    /**
     * Entering an address is an action. If it did not count, the
     * clean-up cron could clear away the cart from under a long checkout
     * — 30 days are long, but the counter would stand still.
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
     * Rebuilds connection and repository, this time with DBAL's logging
     * middleware in between. Creating the schema is not
     * counted — the recording starts empty.
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
 * Hooks in between DBAL and the database via logging middleware: records
 * every statement sent and can interfere exactly once before
 * a line item is inserted.
 *
 * The interference stands in for a second, simultaneous
 * request. An SQLite trigger could not do it: on a constraint error SQLite
 * rolls back the WHOLE statement, including what the
 * trigger wrote — the other row would be gone again afterwards. Here
 * the other INSERT runs as a separate, completed statement before ours
 * even starts.
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
     * The same hook one statement earlier: before the hasLine() SELECT, that is
     * after the first UPDATE has already found nothing. That is the second
     * possible interleaving of two simultaneous calls.
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

        // Exactly once, otherwise the hook interferes with the retry.
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
     * All recorded statements that contain the expression.
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

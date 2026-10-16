<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart\Discount;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartRuleExhaustedAtPurchase;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ProductConditions;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block H, H-4: Warenkorbregeln und Codes (H6, C3, W2–W6).
 *
 * Der Korb: Essig (Klasse 1, 20 %) 2 × 10 netto = 24 brutto, Buch (Klasse 2,
 * 10 %) 1 × 20 netto = 22 brutto — zwei Sätze, damit die Aufteilung zählt.
 */
class CartDiscountsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_product', 'tl_consho_product_property'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'manufacturer' => 3]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 0, 'manufacturer' => 4]);
    }

    public function testProzentJeSteuersatzInDerAnzeigeDesKunden(): void
    {
        $this->rule(['code' => 'SALE10', 'percent' => '10']);

        $result = $this->evaluate('SALE10');

        self::assertSame(CodeStatus::Applied, $result->codeStatus);
        self::assertNotNull($result->discount);
        self::assertSame([[1, 2.0, 2.4], [2, 2.0, 2.2]], $this->shares($result->discount->shares));
        self::assertSame(4.6, (float) $result->discount->displayed());
    }

    public function testDerBetragGiltBruttoUndErgibtGenauIhn(): void
    {
        // W2: 10,00 € so, wie der Kunde liest — aufgeteilt nach Bruttoanteil 24:22.
        $this->rule(['code' => 'ZEHN', 'effect' => 'amount', 'amount' => '10', 'currency' => 'EUR']);

        $discount = $this->evaluate('ZEHN')->discount;

        self::assertNotNull($discount);
        self::assertSame(10.0, (float) $discount->displayed(), 'genau der Betrag, nicht 9,99');
        self::assertSame([[1, 4.35, 5.22], [2, 4.3455, 4.78]], $this->shares($discount->shares));
    }

    public function testFuerNettoKundenGiltDerBetragNetto(): void
    {
        $this->rule(['code' => 'ZEHN', 'effect' => 'amount', 'amount' => '10', 'currency' => 'EUR']);

        $discount = $this->evaluate('ZEHN', showsNet: true)->discount;

        self::assertNotNull($discount);
        self::assertSame(10.0, (float) $discount->displayed());
        self::assertSame([[1, 5.0, 5.0], [2, 5.0, 5.0]], $this->shares($discount->shares));
    }

    public function testDerRundungsrestGehtAufDenGroesstenAnteil(): void
    {
        // 10 € auf drei gleiche Anteile: 3,33 + 3,33 + 3,34 — nicht 9,99.
        $this->rule(['code' => 'ZEHN', 'effect' => 'amount', 'amount' => '10', 'currency' => 'EUR']);

        $cart = new CartView([
            new CartLine(1, 1, 1, '1', '10.0000', '10.0000', '12.0000', 1),
            new CartLine(2, 2, 2, '1', '10.0000', '10.0000', '12.0000', 2),
            new CartLine(3, 2, 2, '1', '10.0000', '10.0000', '12.0000', 3),
        ], [], '3', '30.00', '36.00', 'EUR');

        self::assertSame(10.0, (float) $this->discounts()->evaluate($cart, 1, 'ZEHN', [], false, 0, '', self::NOW)->discount?->displayed());
    }

    public function testNieMehrAlsDieWare(): void
    {
        $this->rule(['code' => 'ALLES', 'effect' => 'amount', 'amount' => '500', 'currency' => 'EUR']);

        self::assertSame(46.0, (float) $this->evaluate('ALLES')->discount?->displayed());
    }

    public function testGrossUndKleinschreibungZaehlt(): void
    {
        // W6, Michael: „Groß- und Kleinschreibung ist nicht egal".
        $this->rule(['code' => 'Sale', 'percent' => '10']);

        self::assertSame(CodeStatus::Unknown, $this->evaluate('SALE')->codeStatus);
        self::assertSame(CodeStatus::Unknown, $this->evaluate('sale')->codeStatus);
        self::assertSame(CodeStatus::Applied, $this->evaluate('  Sale ')->codeStatus, 'Leerzeichen am Rand fallen weg');
    }

    public function testMindestbestellwert(): void
    {
        $this->rule(['code' => 'AB50', 'percent' => '10', 'minimumOrder' => '50', 'currency' => 'EUR']);

        $result = $this->evaluate('AB50');

        self::assertNull($result->discount);
        self::assertSame(CodeStatus::MinimumOrder, $result->codeStatus);
        self::assertSame('4.00', $result->minimumOrder, 'es fehlen 4 € auf 50 €');
    }

    public function testAbosZaehlenNichtZumMindestbestellwert(): void
    {
        // W3: Ein Abo von 10 € hebt den Korb nicht über 50 €.
        $this->rule(['code' => 'AB50', 'percent' => '10', 'minimumOrder' => '50', 'currency' => 'EUR']);

        $cart = new CartView([
            new CartLine(1, 1, 1, '2', '10.0000', '20.0000', '24.0000', 1),
            new CartLine(2, 2, 2, '1', '20.0000', '20.0000', '22.0000', 2),
            new CartLine(3, 2, 2, '1', '10.0000', '10.0000', '12.0000', 1, 5),
        ], [], '4', '50.00', '58.00', 'EUR');

        self::assertSame(CodeStatus::MinimumOrder, $this->discounts()->evaluate($cart, 1, 'AB50', [], false, 0, '', self::NOW)->codeStatus);
    }

    /**
     * WG8: Ein Gutschein ist keine Ware, die ein Rabatt mindert — und kein
     * Bestellwert, der einen Mindestwert erreicht.
     */
    public function testEinGutscheinZaehltWederZumMindestwertNochZumRabatt(): void
    {
        $this->rule(['code' => 'AB50', 'percent' => '10', 'minimumOrder' => '50', 'currency' => 'EUR']);
        $this->rule(['code' => 'ZEHN', 'percent' => '10']);

        $cart = new CartView([
            new CartLine(1, 1, 1, '2', '10.0000', '20.0000', '24.0000', 1),
            new CartLine(2, 2, 2, '1', '20.0000', '20.0000', '22.0000', 2),
            new CartLine(3, 3, 3, '1', '50.0000', '50.0000', '50.0000', 9, kind: ProductKind::Voucher),
        ], [], '4', '90.00', '96.00', 'EUR');

        self::assertSame(CodeStatus::MinimumOrder, $this->discounts()->evaluate($cart, 1, 'AB50', [], false, 0, '', self::NOW)->codeStatus);

        $zehn = $this->discounts()->evaluate($cart, 1, 'ZEHN', [], false, 0, '', self::NOW);
        self::assertNotNull($zehn->discount);
        self::assertSame(4.6, (float) $zehn->discount->displayed(), '10 % von 46 €, nicht von 96 €');
        self::assertArrayNotHasKey(2, $zehn->discount->lines, 'der Gutschein trägt nichts');

        $nurGutschein = new CartView([new CartLine(3, 3, 3, '1', '50.0000', '50.0000', '50.0000', 9, kind: ProductKind::Voucher)], [], '1', '50.00', '50.00', 'EUR');
        self::assertSame(CodeStatus::NoMatchingGoods, $this->discounts()->evaluate($nurGutschein, 1, 'ZEHN', [], false, 0, '', self::NOW)->codeStatus);
    }

    public function testBeiGleichstandGiltDerGetippteCode(): void
    {
        // 10 % = 4,60 € gegen automatisch 4,60 €: der Kunde hat den Code getippt.
        $this->rule(['effect' => 'amount', 'amount' => '4.60', 'currency' => 'EUR']);
        $this->rule(['code' => 'ZEHN', 'percent' => '10']);

        $result = $this->evaluate('ZEHN');

        self::assertSame(CodeStatus::Applied, $result->codeStatus);
        self::assertSame('ZEHN', $result->discount?->code);
    }

    public function testEineAndereWaehrungIstNichtFuerDiesenKorb(): void
    {
        $this->rule(['code' => 'CHF', 'effect' => 'amount', 'amount' => '5', 'currency' => 'CHF']);

        self::assertSame(CodeStatus::NotForYou, $this->evaluate('CHF')->codeStatus);
    }

    public function testNurDieWarenDerBedingungen(): void
    {
        $this->rule(['code' => 'HERSTELLER', 'percent' => '10', 'manufacturers' => serialize(['4'])]);

        $discount = $this->evaluate('HERSTELLER')->discount;

        self::assertNotNull($discount);
        self::assertSame([[2, 2.0, 2.2]], $this->shares($discount->shares));
        // H-5: nur die genannte Zeile bekommt einen Anteil, an ihrer Stelle im Korb.
        self::assertSame([1 => '2.0000'], $discount->lines);
    }

    public function testJedeZeileBekommtIhrenAnteilAmNetto(): void
    {
        // H-5 (R1): Klasse 1 nach dem, was der Kunde liest (12 : 24), der Rest auf die letzte.
        $this->rule(['code' => 'SALE10', 'percent' => '10']);

        $cart = new CartView([
            new CartLine(1, 1, 1, '1', '10.0000', '10.0000', '12.0000', 1),
            new CartLine(2, 2, 2, '1', '20.0000', '20.0000', '22.0000', 2),
            new CartLine(3, 3, 3, '1', '20.0000', '20.0000', '24.0000', 1),
        ], [], '3', '50.00', '58.00', 'EUR');

        $discount = $this->discounts()->evaluate($cart, 1, 'SALE10', [], false, 0, '', self::NOW)->discount;

        self::assertNotNull($discount);
        self::assertSame([[1, 3.0, 3.6], [2, 2.0, 2.2]], $this->shares($discount->shares));
        self::assertSame([0 => '1.0000', 2 => '2.0000', 1 => '2.0000'], $discount->lines);
    }

    /**
     * Review H, S1 (zweites Review, P-2): Tee DE und Beratung AT in derselben
     * Klasse — zwei Anteile, je mit Land; 10 € nach dem, was der Kunde liest
     * (12 : 24), der Rest auf den größten.
     */
    public function testEinBetragTeiltNachKlasseUndLand(): void
    {
        $this->rule(['code' => 'ZEHN', 'effect' => 'amount', 'amount' => '10', 'currency' => 'EUR']);

        $cart = new CartView([
            new CartLine(1, 1, 1, '1', '10.0000', '10.0000', '12.0000', 1, taxCountry: 'DE'),
            new CartLine(2, 2, 2, '1', '20.0000', '20.0000', '24.0000', 1, taxCountry: 'AT'),
        ], [], '2', '30.00', '36.00', 'EUR');

        $discount = $this->discounts()->evaluate($cart, 1, 'ZEHN', [], false, 0, '', self::NOW)->discount;

        self::assertNotNull($discount);
        self::assertSame([['DE', '3.33'], ['AT', '6.67']], array_map(static fn ($share): array => [$share->taxCountry, $share->gross], $discount->shares));
        self::assertSame([0 => '2.7750', 1 => '5.5583'], $discount->lines);
    }

    public function testDerAnteilJeZeileErgibtGenauDenAnteilDerKlasse(): void
    {
        // 10 € brutto auf drei gleiche Zeilen einer Klasse: 3,3333 + 3,3333 + Rest.
        $this->rule(['code' => 'ZEHN', 'effect' => 'amount', 'amount' => '10', 'currency' => 'EUR']);

        $cart = new CartView([
            new CartLine(1, 1, 1, '1', '10.0000', '10.0000', '12.0000', 1),
            new CartLine(2, 2, 2, '1', '10.0000', '10.0000', '12.0000', 1),
            new CartLine(3, 3, 3, '1', '10.0000', '10.0000', '12.0000', 1),
        ], [], '3', '30.00', '36.00', 'EUR');

        $discount = $this->discounts()->evaluate($cart, 1, 'ZEHN', [], false, 0, '', self::NOW)->discount;

        self::assertNotNull($discount);
        self::assertSame('8.3333', $discount->shares[0]->net);
        self::assertSame(['2.7778', '2.7778', '2.7777'], array_values($discount->lines));
    }

    public function testReduzierteWareNurMitHaekchen(): void
    {
        $this->rule(['code' => 'X', 'percent' => '10']);
        self::assertSame([[2, 2.0, 2.2]], $this->shares($this->evaluate('X', promotionOnFirst: true)->discount->shares ?? []));

        $this->connection->executeStatement('UPDATE tl_consho_cart_rule SET reduced = 1');
        self::assertSame(4.6, (float) (new CartDiscounts($this->connection, new ProductConditions($this->connection)))->evaluate($this->cart(true), 1, 'X', [], false, 0, '', self::NOW)->discount?->displayed());
    }

    public function testAboZeilenZaehlenNie(): void
    {
        $this->rule(['code' => 'X', 'percent' => '10']);

        $result = (new CartDiscounts($this->connection, new ProductConditions($this->connection)))->evaluate(
            new CartView([new CartLine(1, 1, 1, '1', '10.0000', '10.0000', '12.0000', 1, 5)], [], '1', '10.00', '12.00', 'EUR'),
            1, 'X', [], false, 0, '', self::NOW,
        );

        self::assertSame(CodeStatus::NoMatchingGoods, $result->codeStatus);
    }

    public function testDieGuenstigereRegelGiltKeineSumme(): void
    {
        // C3: Code 20 % (9,20 €) gegen automatisch 5 € — der bessere, nicht beide.
        $this->rule(['code' => 'ZWANZIG', 'percent' => '20']);
        $this->rule(['effect' => 'amount', 'amount' => '5', 'currency' => 'EUR', 'title' => 'Automatisch']);

        $result = $this->evaluate('ZWANZIG');
        self::assertSame(CodeStatus::Applied, $result->codeStatus);
        self::assertSame(9.2, (float) $result->discount?->displayed(), 'nicht 14,20 €');
    }

    public function testEinSchwaechererCodeWirdVerdraengt(): void
    {
        $this->rule(['code' => 'EINS', 'percent' => '1']);
        $this->rule(['effect' => 'amount', 'amount' => '5', 'currency' => 'EUR']);

        $result = $this->evaluate('EINS');

        self::assertSame(CodeStatus::Superseded, $result->codeStatus);
        self::assertSame('', $result->discount?->code);
    }

    public function testOhneCodeDieAutomatischeRegel(): void
    {
        $this->rule(['percent' => '10']);

        $result = $this->evaluate('');

        self::assertSame(CodeStatus::None, $result->codeStatus);
        self::assertSame(4.6, (float) $result->discount?->displayed());
    }

    public function testZeitraumUndGruppen(): void
    {
        $this->rule(['code' => 'SPAETER', 'percent' => '10', 'validFrom' => self::NOW + 1]);
        $this->rule(['code' => 'GRUPPE', 'percent' => '10', 'memberGroups' => serialize(['7'])]);

        self::assertSame(CodeStatus::NotActive, $this->evaluate('SPAETER')->codeStatus);
        self::assertSame(CodeStatus::NotForYou, $this->evaluate('GRUPPE')->codeStatus);
        self::assertSame(CodeStatus::Applied, $this->evaluate('GRUPPE', groups: [7])->codeStatus);
    }

    public function testVersandkostenfreiIstSoVielWertWieDerVersand(): void
    {
        $this->rule(['code' => 'FREI', 'effect' => 'freeShipping']);
        $this->rule(['effect' => 'amount', 'amount' => '3', 'currency' => 'EUR']);

        $inTheBasket = $this->discounts()->evaluate($this->cart(), 1, 'FREI', [], false, 0, '', self::NOW);
        self::assertSame(CodeStatus::Superseded, $inTheBasket->codeStatus, 'im Warenkorb ist der Versand noch 0');

        $atCheckout = $this->discounts()->evaluate($this->cart(), 1, 'FREI', [], false, 0, '', self::NOW, '5.90');
        self::assertSame(CodeStatus::Applied, $atCheckout->codeStatus);
        self::assertTrue($atCheckout->discount?->freeShipping);
    }

    public function testEinloesungenInsgesamtUndJeKunde(): void
    {
        $this->rule(['code' => 'EINMAL', 'percent' => '10', 'maxUsesPerCustomer' => 1]);
        $this->rule(['code' => 'ZWEI', 'percent' => '10', 'maxUses' => 2]);

        $this->discounts()->redeem(1, 'EINMAL', 100, 0, 'Gast@Example.org', self::NOW);
        self::assertSame(CodeStatus::Exhausted, $this->evaluate('EINMAL', email: 'gast@example.org ')->codeStatus, 'W5: der Gast nach Adresse');
        self::assertSame(CodeStatus::Applied, $this->evaluate('EINMAL', email: 'andere@example.org')->codeStatus);

        $this->discounts()->redeem(2, 'ZWEI', 101, 5, '', self::NOW);
        $this->discounts()->redeem(2, 'ZWEI', 102, 6, '', self::NOW);
        self::assertSame(CodeStatus::Exhausted, $this->evaluate('ZWEI', member: 7)->codeStatus);
    }

    public function testDerKaufklickNimmtDieLetzteEinloesungNurEinmal(): void
    {
        $this->rule(['code' => 'LETZTE', 'percent' => '10', 'maxUses' => 1]);
        $this->discounts()->redeem(1, 'LETZTE', 100, 5, '', self::NOW);

        $this->expectException(CartRuleExhaustedAtPurchase::class);
        $this->discounts()->redeem(1, 'LETZTE', 101, 6, '', self::NOW);
    }

    public function testEinStornoGibtDieEinloesungZurueck(): void
    {
        $this->rule(['code' => 'LETZTE', 'percent' => '10', 'maxUses' => 1]);
        $this->discounts()->redeem(1, 'LETZTE', 100, 5, '', self::NOW);
        self::assertSame(CodeStatus::Exhausted, $this->evaluate('LETZTE')->codeStatus);

        // H-5: Ein anderer Storno gibt nichts zurück, der eigene schon — die Zeile bleibt.
        $this->discounts()->afterCancellation(101, self::NOW + 1);
        self::assertSame(CodeStatus::Exhausted, $this->evaluate('LETZTE')->codeStatus);

        $this->discounts()->afterCancellation(100, self::NOW + 1);
        self::assertSame(CodeStatus::Applied, $this->evaluate('LETZTE')->codeStatus);
        self::assertSame(['1', (string) (self::NOW + 1)], array_map('strval', array_values($this->connection->fetchAssociative('SELECT cancelled, tstamp FROM tl_consho_cart_rule_redemption') ?: [])));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function rule(array $values): void
    {
        $this->connection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Rabatt', 'published' => 1, 'effect' => 'percent', ...$values]);
    }

    /**
     * @param list<int> $groups
     */
    private function evaluate(string $code, bool $showsNet = false, array $groups = [], int $member = 0, string $email = '', bool $promotionOnFirst = false): \Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscountResult
    {
        return $this->discounts()->evaluate($this->cart($promotionOnFirst), 1, $code, $groups, $showsNet, $member, $email, self::NOW);
    }

    private function discounts(): CartDiscounts
    {
        return new CartDiscounts($this->connection, new ProductConditions($this->connection));
    }

    private function cart(bool $promotionOnFirst = false): CartView
    {
        return new CartView([
            new CartLine(1, 1, 1, '2', '10.0000', '20.0000', '24.0000', 1, source: $promotionOnFirst ? PriceSource::Promotion : PriceSource::List),
            new CartLine(2, 2, 2, '1', '20.0000', '20.0000', '22.0000', 2),
        ], [], '3', '40.00', '46.00', 'EUR');
    }

    /**
     * @param list<\Webwerkwien\ContaoConshoBundle\Checkout\ShippingShare> $shares
     *
     * @return list<array{int, float, float}>
     */
    private function shares(array $shares): array
    {
        return array_map(static fn ($share): array => [$share->taxClassId, (float) $share->net, (float) $share->gross], $shares);
    }
}

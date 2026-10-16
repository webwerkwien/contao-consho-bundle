<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Cart\CodeAttemptLimit;
use Webwerkwien\ContaoConshoBundle\Cart\CodeEntry;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CartDiscounts;
use Webwerkwien\ContaoConshoBundle\Pricing\ProductConditions;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherWallet;

/**
 * WG5: Die Code-Eingabe im Warenkorb hatte keine Bremse — ein Skript konnte
 * Code um Code probieren. Gezählt werden nur Fehlversuche, je IP und je
 * Warenkorb; hier mit einer Grenze von drei statt zehn.
 */
class CodeEntryTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    private DbalCartRepository $carts;

    private InMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_cart', 'tl_consho_cart_rule', 'tl_consho_voucher'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_cart', ['id' => 1]);
        $this->connection->insert('tl_consho_cart', ['id' => 2]);
        $this->connection->insert('tl_consho_cart_rule', ['tstamp' => 1, 'title' => 'Herbst', 'published' => 1, 'effect' => 'percent', 'percent' => '10', 'code' => 'HERBST']);

        $this->carts = new DbalCartRepository($this->connection);
        $this->storage = new InMemoryStorage();
    }

    public function testNachDreiUnbekanntenCodesWirdNichtsMehrGeprueft(): void
    {
        foreach (['A1', 'A2', 'A3'] as $guess) {
            $this->entry()->submit(1, $guess, '10.0.0.1', self::NOW);
        }

        self::assertNull($this->carts->takeNotice(1));

        // Auch ein gültiger Code wird jetzt nicht mehr angesehen.
        $this->entry()->submit(1, 'HERBST', '10.0.0.1', self::NOW);

        self::assertSame('A3', $this->carts->discountCodeOf(1));
        self::assertSame(CartNotice::TooManyCodes, $this->carts->takeNotice(1));
    }

    public function testEinBekannterCodeZaehltNieAlsFehlversuch(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->entry()->submit(1, 'HERBST', '10.0.0.1', self::NOW);
        }

        self::assertSame('HERBST', $this->carts->discountCodeOf(1));
        self::assertNull($this->carts->takeNotice(1));
    }

    /**
     * W6: „herbst" ist ein anderer Code als „HERBST" — und damit ein
     * Fehlversuch.
     */
    public function testGrossUndKleinschreibungZaehlt(): void
    {
        foreach (['herbst', 'Herbst', 'hERBST'] as $guess) {
            $this->entry()->submit(1, $guess, '10.0.0.1', self::NOW);
        }

        $this->entry()->submit(1, 'HERBST', '10.0.0.1', self::NOW);

        self::assertSame(CartNotice::TooManyCodes, $this->carts->takeNotice(1));
    }

    public function testEntfernenWirdNieGebremst(): void
    {
        foreach (['A1', 'A2', 'A3'] as $guess) {
            $this->entry()->submit(1, $guess, '10.0.0.1', self::NOW);
        }

        $this->entry()->submit(1, '', '10.0.0.1', self::NOW);

        self::assertSame('', $this->carts->discountCodeOf(1));
        self::assertNull($this->carts->takeNotice(1));
    }

    /**
     * Ein neuer Warenkorb beginnt nicht bei null, solange dieselbe IP rät.
     */
    public function testDieIpZaehltUeberWarenkoerbeHinweg(): void
    {
        foreach (['A1', 'A2', 'A3'] as $guess) {
            $this->entry()->submit(1, $guess, '10.0.0.1', self::NOW);
        }

        $this->entry()->submit(2, 'B1', '10.0.0.1', self::NOW);

        self::assertSame('', $this->carts->discountCodeOf(2));
        self::assertSame(CartNotice::TooManyCodes, $this->carts->takeNotice(2));
    }

    /**
     * Und eine neue IP nicht, solange derselbe Warenkorb rät.
     */
    public function testDerWarenkorbZaehltUeberIpsHinweg(): void
    {
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->entry()->submit(1, 'X-'.$ip, $ip, self::NOW);
        }

        $this->entry()->submit(1, 'HERBST', '10.0.0.4', self::NOW);

        self::assertSame(CartNotice::TooManyCodes, $this->carts->takeNotice(1));
    }

    /**
     * WG8 (E3): getippt wird, wie es auf dem Papier steht — klein, mit
     * Leerzeichen und Bindestrichen; gespeichert wird der Code selbst, in
     * der Reihenfolge der Eingabe, jeder einmal.
     */
    public function testGutscheinWirdNormalisiertUndInReihenfolgeGemerkt(): void
    {
        $this->voucher(1, 'AAAABBBBCCCCDDDD');
        $this->voucher(2, 'EEEEFFFFGGGGHHHH');

        $this->entry()->addVoucher(1, 1, 'eeee-ffff gggg-hhhh', '10.0.0.1', self::NOW);
        $this->entry()->addVoucher(1, 1, 'AAAA-BBBB-CCCC-DDDD', '10.0.0.1', self::NOW);
        $this->entry()->addVoucher(1, 1, 'aaaabbbbccccdddd', '10.0.0.1', self::NOW);

        self::assertSame(['EEEEFFFFGGGGHHHH', 'AAAABBBBCCCCDDDD'], $this->carts->vouchersOf(1));
        self::assertNull($this->carts->takeNotice(1));

        $this->entry()->removeVoucher(1, 'EEEE-FFFF-GGGG-HHHH', self::NOW);

        self::assertSame(['AAAABBBBCCCCDDDD'], $this->carts->vouchersOf(1));
    }

    /**
     * Ein Gutschein ist Geld: ein falscher Code wird nicht gemerkt, gemeldet
     * und gezählt — und ein Gutschein eines anderen Shops ist hier ein
     * falscher Code (WG9), nicht ein verratener.
     */
    public function testUnbekannterGutscheinWirdGezaehltUndNichtGemerkt(): void
    {
        $this->voucher(1, 'AAAABBBBCCCCDDDD', shop: 2);

        $this->entry()->addVoucher(1, 1, 'AAAABBBBCCCCDDDD', '10.0.0.1', self::NOW);

        self::assertSame([], $this->carts->vouchersOf(1));
        self::assertSame(CartNotice::VoucherUnknown, $this->carts->takeNotice(1));

        $this->entry()->addVoucher(1, 1, 'ZZZZ', '10.0.0.1', self::NOW);
        $this->entry()->addVoucher(1, 1, 'YYYY', '10.0.0.1', self::NOW);
        $this->voucher(2, 'EEEEFFFFGGGGHHHH');
        $this->entry()->addVoucher(1, 1, 'EEEEFFFFGGGGHHHH', '10.0.0.1', self::NOW);

        // Die Bremse ist dieselbe wie für Rabattcodes: der vierte, gültige
        // Code wird nicht mehr angesehen.
        self::assertSame([], $this->carts->vouchersOf(1));
        self::assertSame(CartNotice::TooManyCodes, $this->carts->takeNotice(1));
    }

    /**
     * Freigeschaltet für weitere Shops (WG9): dort gilt er wie im eigenen.
     */
    public function testGutscheinGiltInFreigeschaltetemShop(): void
    {
        $this->voucher(1, 'AAAABBBBCCCCDDDD', shop: 2, shops: serialize(['3', '1']));

        $this->entry()->addVoucher(1, 1, 'AAAABBBBCCCCDDDD', '10.0.0.1', self::NOW);

        self::assertSame(['AAAABBBBCCCCDDDD'], $this->carts->vouchersOf(1));
    }

    public function testMehrAlsZehnGutscheineNimmtDerKorbNicht(): void
    {
        $codes = [];

        for ($i = 1; $i <= CodeEntry::MAX_VOUCHERS + 1; ++$i) {
            $codes[] = $code = str_pad(substr('ABCDEFGHJK', $i - 1, 1).'A', 16, 'B');
            $this->voucher($i, $code);
            $this->entry()->addVoucher(1, 1, $code, '10.0.0.1', self::NOW);
        }

        self::assertSame(\array_slice($codes, 0, CodeEntry::MAX_VOUCHERS), $this->carts->vouchersOf(1));
        self::assertSame(CartNotice::TooManyVouchers, $this->carts->takeNotice(1));
    }

    private function voucher(int $id, string $code, int $shop = 1, string|null $shops = null): void
    {
        $this->connection->insert('tl_consho_voucher', ['id' => $id, 'code' => $code, 'shop' => $shop, 'shops' => $shops, 'currency' => 'EUR', 'faceValue' => '20', 'balance' => '20']);
    }

    private function entry(): CodeEntry
    {
        $limiter = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '15 minutes'], $this->storage);

        return new CodeEntry($this->carts, new CartDiscounts($this->connection, new ProductConditions($this->connection)), new CodeAttemptLimit($limiter), new VoucherWallet($this->connection));
    }
}

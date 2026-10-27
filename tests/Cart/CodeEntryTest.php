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
 * WG5: the code entry in the cart had no brake — a script could try
 * code after code. Only failed attempts are counted, per IP and per
 * cart; here with a limit of three instead of ten.
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

        // Even a valid code is no longer looked at now.
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
     * W6: "herbst" is a different code from "HERBST" — and thus a
     * failed attempt.
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
     * A new cart does not start at zero as long as the same IP keeps guessing.
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
     * Nor does a new IP, as long as the same cart keeps guessing.
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
     * WG8 (E3): it is typed as it is printed on paper — lower case, with
     * spaces and hyphens; what is stored is the code itself, in
     * the order of entry, each one once.
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
     * A voucher is money: a wrong code is not remembered, it is reported
     * and counted — and a voucher of another shop is a
     * wrong code here (WG9), not a revealed one.
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

        // The brake is the same as for discount codes: the fourth, valid
        // code is no longer looked at.
        self::assertSame([], $this->carts->vouchersOf(1));
        self::assertSame(CartNotice::TooManyCodes, $this->carts->takeNotice(1));
    }

    /**
     * Enabled for further shops (WG9): there it applies as in its own.
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

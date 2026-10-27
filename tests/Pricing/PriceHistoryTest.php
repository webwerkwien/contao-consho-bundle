<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPrice;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceHistory;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block H, H-2: the reference price under § 9a PrAG / § 11 PAngV — the lowest
 * price of the 30 days BEFORE the start of the reduction.
 */
class PriceHistoryTest extends TestCase
{
    private const DAY = 86400;

    private const T0 = 1_800_000_000;

    private Connection $connection;

    private PriceHistory $history;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_price_history');
        $this->history = new PriceHistory($this->connection);
    }

    public function testEinUnveraenderterPreisSchreibtKeinenZweitenEintrag(): void
    {
        $this->at(-10, '10.0000');
        $this->at(-5, '10.0000');
        $this->at(-1, '10');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_price_history'));
    }

    public function testDerFallAusDerSpec(): void
    {
        // €10 for a long time, raised to €12 five days ago, today a €9 promotion:
        // €10 is struck through, not €12.
        $this->at(-40, '10.0000');
        $this->at(-5, '12.0000');
        $this->at(0, '9.0000', promotion: true);

        self::assertSame(['10.0000', '12.0000'], $this->reference(0));
    }

    public function testDerBezugspreisBleibtWaehrendDerAktionStehen(): void
    {
        // Counted back from today, the promotion itself would soon be the lowest price.
        $this->at(-40, '10.0000');
        $this->at(0, '9.0000', promotion: true);

        self::assertSame(['10.0000', '12.0000'], $this->reference(25));
    }

    public function testEineAktionUeberDreissigTageBehaeltIhrenBezugspreis(): void
    {
        // The window hangs on the start of the promotion, not on today —
        // otherwise the price before it would drop out after 30 days.
        $this->at(-40, '10.0000');
        $this->at(0, '9.0000', promotion: true);

        self::assertSame(['10.0000', '12.0000'], $this->reference(45));
    }

    public function testEinPreisDerInDasFensterHineinreichtZaehlt(): void
    {
        // €8 applied until day −29, so within the 30 days before the start.
        $this->at(-45, '8.0000');
        $this->at(-29, '11.0000');
        $this->at(0, '9.0000', promotion: true);

        self::assertSame(['8.0000', '9.6000'], $this->reference(0));
    }

    public function testEinPreisDerVorDemFensterEndeteZaehltNicht(): void
    {
        $this->at(-45, '8.0000');
        $this->at(-31, '11.0000');
        $this->at(0, '9.0000', promotion: true);

        self::assertSame(['11.0000', '13.2000'], $this->reference(0));
    }

    public function testSchrittweiseAnsteigendeErmaessigungVergleichtMitDemPreisDavor(): void
    {
        // § 9a para. 1 sentence 2: the price before the first stage.
        $this->at(-40, '10.0000');
        $this->at(0, '9.0000', promotion: true);
        $this->at(5, '8.0000', promotion: true);

        self::assertSame(['10.0000', '12.0000'], $this->reference(6));
    }

    /**
     * H: a stage that is MORE EXPENSIVE than the previous one is no longer
     * a rising reduction — for it the general principle applies, the
     * lowest price of the 30 days before it: 7.00, not 10.00.
     */
    public function testEineTeurereStufeVergleichtMitDerVorigen(): void
    {
        $this->at(-40, '10.0000');
        $this->at(0, '7.0000', promotion: true);
        $this->at(5, '8.0000', promotion: true);

        self::assertSame(['7.0000', '8.4000'], $this->reference(6));
    }

    public function testNachEinerTeurerenStufeBeginntDieFolgeDort(): void
    {
        // 10 → 7 → 8 → 6: the 6 stage lowers the price without interruption from the 8 stage.
        $this->at(-40, '10.0000');
        $this->at(0, '7.0000', promotion: true);
        $this->at(5, '8.0000', promotion: true);
        $this->at(10, '6.0000', promotion: true);

        self::assertSame(['7.0000', '8.4000'], $this->reference(11));
    }

    public function testGleichBleibendeStufeUnterbrichtNicht(): void
    {
        $this->at(-40, '10.0000');
        $this->at(0, '8.0000', promotion: true);
        $this->at(5, '8.0000', promotion: true);

        self::assertSame(['10.0000', '12.0000'], $this->reference(6));
    }

    public function testOhneVerlaufVorDerAktionGibtEsKeinenBezugspreis(): void
    {
        $this->at(0, '9.0000', promotion: true);

        self::assertNull($this->reference(0));
    }

    public function testOhneAktionGibtEsKeinenBezugspreis(): void
    {
        $this->at(-40, '10.0000');
        $this->at(0, '9.0000');

        self::assertNull($this->reference(0));
    }

    public function testEinAbopreisIstKeinNiedrigererPreisDesEinmalkaufs(): void
    {
        $this->at(-40, '10.0000');
        $this->at(-20, '1.0000', rule: 3);
        $this->at(-10, '10.0000');
        $this->at(0, '9.0000', promotion: true);

        self::assertSame(['10.0000', '12.0000'], $this->reference(0));
    }

    public function testJederMarktHatSeinenVerlauf(): void
    {
        $this->at(-40, '10.0000');
        $this->history->record(1, 'EUR', 'DE', new ListPrice('7.0000', '8.3300'), self::T0 - 40 * self::DAY);
        $this->at(0, '9.0000', promotion: true);

        self::assertSame(['10.0000', '12.0000'], $this->reference(0));
    }

    private function at(int $day, string $net, bool $promotion = false, int $rule = 0): void
    {
        $gross = (string) \Brick\Math\BigDecimal::of($net)->multipliedBy('1.2')->toScale(4);
        $this->history->record(1, 'EUR', 'AT', new ListPrice($net, $gross, $rule, promotion: $promotion), self::T0 + $day * self::DAY);
    }

    /**
     * @return array{string, string}|null
     */
    private function reference(int $day): array|null
    {
        $reference = $this->history->reference(1, 'EUR', 'AT', self::T0 + $day * self::DAY);

        // SQLite returns NUMERIC without trailing zeros — the amount is compared.
        return null === $reference ? null : array_map(static fn (string $v): string => (string) \Brick\Math\BigDecimal::of($v)->toScale(4), $reference);
    }
}

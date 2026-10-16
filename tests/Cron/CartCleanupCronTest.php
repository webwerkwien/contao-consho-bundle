<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cron;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartLifetime;
use Webwerkwien\ContaoConshoBundle\Cron\CartCleanupCron;
use Webwerkwien\ContaoConshoBundle\Tests\Cart\CartSchema;

class CartCleanupCronTest extends TestCase
{
    use CartSchema;

    private const NOW = 2_000_000_000;

    protected function setUp(): void
    {
        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCartTables();
    }

    public function testEinOffenerWarenkorbUeberDerFristVerschwindetMitSeinenZeilen(): void
    {
        $old = $this->insertCart(['state' => 'open', 'updatedAt' => self::NOW - CartLifetime::OPEN - 1]);
        $this->insertCartItem(['pid' => $old, 'product' => 7, 'quantity' => '1.0000']);

        $this->cron()();

        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'), 'sonst bleiben verwaiste Zeilen liegen');
    }

    public function testEinOffenerWarenkorbGenauAufDerFristBleibt(): void
    {
        $this->insertCart(['state' => 'open', 'updatedAt' => self::NOW - CartLifetime::OPEN]);

        $this->cron()();

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
    }

    public function testEinUmgewandelterWarenkorbHatDieKuerzereFrist(): void
    {
        // Alles Aufbewahrungswürdige steht dann in der Bestellung.
        $this->insertCart(['state' => 'converted', 'updatedAt' => self::NOW - CartLifetime::CONVERTED - 1]);
        $this->insertCart(['state' => 'converted', 'updatedAt' => self::NOW - CartLifetime::CONVERTED + 1]);

        $this->cron()();

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
    }

    public function testEinFrischerWarenkorbBleibt(): void
    {
        $this->insertCart(['state' => 'open', 'updatedAt' => self::NOW - 60]);

        $this->cron()();

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
    }

    public function testVerwaisteZeilenVerschwindenAuchOhneZugehoerigenWarenkorb(): void
    {
        // Ein Datenbankeingriff von Hand hinterlässt sie; nichts anderes räumt
        // sie je weg.
        $this->insertCartItem(['pid' => 999, 'product' => 7, 'quantity' => '1.0000']);

        $this->cron()();

        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }

    public function testDieKassendatenVerfallenNachSiebenTagenAuchWennDerWarenkorbBleibt(): void
    {
        // E1: die Adresse hat eine eigene Uhr, unabhängig von den Zeilen.
        $old = $this->insertCart(['state' => 'open', 'updatedAt' => self::NOW - 60, 'checkoutData' => '{"email":"a@b.at"}', 'checkoutDataAt' => self::NOW - CartLifetime::CHECKOUT_DATA - 1]);
        $edge = $this->insertCart(['state' => 'open', 'updatedAt' => self::NOW - 60, 'checkoutData' => '{"email":"c@d.at"}', 'checkoutDataAt' => self::NOW - CartLifetime::CHECKOUT_DATA]);

        $this->cron()();

        self::assertNull($this->cartConnection->fetchOne('SELECT checkoutData FROM tl_consho_cart WHERE id = ?', [$old]));
        self::assertNotNull($this->cartConnection->fetchOne('SELECT checkoutData FROM tl_consho_cart WHERE id = ?', [$edge]), 'genau auf der Frist bleibt sie');
        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'), 'der Warenkorb selbst bleibt');
    }

    public function testAlteWarenkoerbeOhneZeitstempelGehenNachUpdatedAt(): void
    {
        $old = $this->insertCart(['state' => 'open', 'updatedAt' => self::NOW - CartLifetime::CHECKOUT_DATA - 1, 'checkoutData' => '{"email":"a@b.at"}']);
        $fresh = $this->insertCart(['state' => 'open', 'updatedAt' => self::NOW - 60, 'checkoutData' => '{"email":"c@d.at"}']);

        $this->cron()();

        self::assertNull($this->cartConnection->fetchOne('SELECT checkoutData FROM tl_consho_cart WHERE id = ?', [$old]));
        self::assertNotNull($this->cartConnection->fetchOne('SELECT checkoutData FROM tl_consho_cart WHERE id = ?', [$fresh]), 'sonst verlöre ein Gast mitten in der Kasse seine Adresse beim Deploy');
    }

    private function cron(): CartCleanupCron
    {
        return new CartCleanupCron($this->cartConnection, static fn () => self::NOW);
    }
}

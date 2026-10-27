<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Customer\AccountPasswordPage;
use Webwerkwien\ContaoConshoBundle\Customer\AccountPasswordPageIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * K1-2: a shop with guest checkout and an order page, but no published "Lost
 * password" page on its start point — a note, one per shop.
 */
final class AccountPasswordPageIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop', 'guestCheckout' => 1]);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Shop DE', 'published' => 1, 'consho_shop' => 1, 'consho_orderPage' => 10]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'title' => 'Shop EN', 'published' => 1, 'consho_shop' => 1, 'consho_orderPage' => 20]);
        $this->connection->insert('tl_page', ['id' => 30, 'pid' => 1, 'type' => 'regular', 'title' => 'Passwort', 'published' => 1]);
    }

    public function testStartpunkteOhneSeiteWerdenGenannt(): void
    {
        $issues = ($this->source())->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::AccountPasswordPageMissing, $issues[0]->finding);
        self::assertFalse($issues[0]->finding->isWarning(), 'ein Hinweis');
        self::assertSame(['shop' => 'Webshop', 'roots' => 'Shop DE, Shop EN'], $issues[0]->context);
    }

    public function testEineVeroeffentlichteSeiteGenuegt(): void
    {
        $this->connection->update('tl_page', ['consho_passwordPage' => 30], ['id' => 1]);

        self::assertSame('Shop EN', ($this->source())->issues()[0]->context['roots']);

        $this->connection->update('tl_page', ['published' => 0], ['id' => 30]);

        self::assertSame('Shop DE, Shop EN', ($this->source())->issues()[0]->context['roots'], 'eine unveröffentlichte zählt nicht');
    }

    /**
     * The same test as the offer (AccountPasswordPage): below its own start
     * point, published on the whole way, nothing protected.
     */
    public function testNurEineErreichbareSeiteUnterDemEigenenStartpunktZaehlt(): void
    {
        $this->connection->insert('tl_page', ['id' => 31, 'pid' => 1, 'type' => 'regular', 'title' => 'Konto', 'published' => 1, 'protected' => 1]);
        $this->connection->update('tl_page', ['pid' => 31], ['id' => 30]);
        $this->connection->update('tl_page', ['consho_passwordPage' => 30], ['id' => 1]);
        $this->connection->update('tl_page', ['consho_passwordPage' => 30], ['id' => 2]);

        self::assertSame('Shop DE, Shop EN', $this->roots(), 'geschützt über den Vorfahren; für EN ein fremder Startpunkt');

        $this->connection->update('tl_page', ['protected' => 0], ['id' => 31]);
        self::assertSame('Shop EN', $this->roots());

        $this->connection->update('tl_page', ['stop' => (string) (time() - 60)], ['id' => 31]);
        self::assertSame('Shop DE, Shop EN', $this->roots(), 'abgelaufen');
    }

    public function testOhneGastbestellungKeinHinweis(): void
    {
        $this->connection->update('tl_consho_shop', ['guestCheckout' => 0], ['id' => 1]);

        self::assertSame([], ($this->source())->issues());
    }

    public function testOhneBestellseiteKeinHinweis(): void
    {
        $this->connection->update('tl_page', ['consho_orderPage' => 0], ['id' => 1]);
        $this->connection->update('tl_page', ['published' => 0], ['id' => 2]);

        self::assertSame([], ($this->source())->issues());
    }

    private function roots(): string
    {
        return (string) ($this->source()->issues()[0]->context['roots'] ?? '');
    }

    private function source(): AccountPasswordPageIssueSource
    {
        return new AccountPasswordPageIssueSource($this->connection, new AccountPasswordPage($this->connection));
    }
}

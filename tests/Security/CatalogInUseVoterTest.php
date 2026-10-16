<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogReferences;
use Webwerkwien\ContaoConshoBundle\Security\CatalogInUseVoter;
use Webwerkwien\ContaoConshoBundle\Security\MailTransportInUseVoter;
use Webwerkwien\ContaoConshoBundle\Security\ManufacturerInUseVoter;
use Webwerkwien\ContaoConshoBundle\Security\OrderStatusInUseVoter;
use Webwerkwien\ContaoConshoBundle\Security\ProductTypeInUseVoter;
use Webwerkwien\ContaoConshoBundle\Security\PropertyGroupInUseVoter;
use Webwerkwien\ContaoConshoBundle\Security\PropertyOptionInUseVoter;
use Webwerkwien\ContaoConshoBundle\Security\ShopInUseVoter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * CW-4b (H4): verwendete Stammdaten sind in der Maske nicht löschbar.
 * Typ 2, Hersteller 3, Eigenschaft 4 mit Wert 11 hängen an Produkt 5.
 */
class CatalogInUseVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_manufacturer', 'tl_consho_property_option', 'tl_consho_product_property', 'tl_consho_product_option', 'tl_consho_price_rule', 'tl_consho_cart_rule', 'tl_consho_shop', 'tl_consho_order', 'tl_consho_subscription', 'tl_consho_voucher', 'tl_consho_product_shop'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->executeStatement('CREATE TABLE tl_content (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT \'\', conshoFilter BLOB NULL)');
        $this->connection->executeStatement('CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, consho_shop INTEGER NOT NULL DEFAULT 0)');
        $this->connection->insert('tl_consho_product', ['id' => 5, 'productType' => 2, 'manufacturer' => 3]);
        $this->connection->insert('tl_consho_property_option', ['id' => 11, 'pid' => 4]);
        $this->connection->insert('tl_consho_product_property', ['pid' => 5, 'optionId' => 11]);

        // CW-5 (I6): Bestellung 30 in Shop 1 mit Status 12, Shop 1 versendet über 6.
        $this->connection->insert('tl_consho_order', ['id' => 30, 'shop' => 1, 'orderStatus' => 12]);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'mailTransport' => 6]);
    }

    /**
     * @return iterable<string, array{class-string<CatalogInUseVoter>, string, int, int}>
     */
    public static function voters(): iterable
    {
        yield 'Produkttyp' => [ProductTypeInUseVoter::class, 'tl_consho_product_type', 2, 9];
        yield 'Hersteller' => [ManufacturerInUseVoter::class, 'tl_consho_manufacturer', 3, 9];
        yield 'Eigenschaft' => [PropertyGroupInUseVoter::class, 'tl_consho_property_group', 4, 9];
        yield 'Wert' => [PropertyOptionInUseVoter::class, 'tl_consho_property_option', 11, 9];
        yield 'Shop' => [ShopInUseVoter::class, 'tl_consho_shop', 1, 9];
        yield 'Bestellstatus' => [OrderStatusInUseVoter::class, 'tl_consho_order_status', 12, 9];
        yield 'Mail-Versand' => [MailTransportInUseVoter::class, 'tl_consho_mail_transport', 6, 9];
    }

    /**
     * @param class-string<CatalogInUseVoter> $class
     *
     * @dataProvider voters
     */
    public function testVerwendetNichtLoeschbarUnbenutztSchon(string $class, string $table, int $used, int $unused): void
    {
        $voter = new $class(new CatalogReferences($this->connection));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new DeleteAction($table, ['id' => (string) $used])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new DeleteAction($table, ['id' => (string) $unused])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction($table, ['id' => (string) $used], ['title' => 'Neu'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new ReadAction($table, ['id' => (string) $used])));
    }

    public function testAndereTabellenGehenDenVoterNichtsAn(): void
    {
        $voter = new ProductTypeInUseVoter(new CatalogReferences($this->connection));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new DeleteAction('tl_consho_manufacturer', ['id' => '3'])));
        self::assertFalse($voter->supportsType(UpdateAction::class));
    }

    public function testDieMemoisierungGiltBisZumReset(): void
    {
        $voter = new ProductTypeInUseVoter(new CatalogReferences($this->connection));
        $action = new DeleteAction('tl_consho_product_type', ['id' => '9']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $action));

        $this->connection->insert('tl_consho_product', ['id' => 6, 'productType' => 9]);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $action));

        $voter->reset();
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $action));
    }

    private function vote(CatalogInUseVoter $voter, DeleteAction|ReadAction|UpdateAction $action): int
    {
        return $voter->vote($this->createMock(TokenInterface::class), $action, [ContaoCorePermissions::DC_PREFIX.$action->getDataSource()]);
    }
}

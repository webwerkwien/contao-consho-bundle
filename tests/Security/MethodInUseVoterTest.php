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
use Webwerkwien\ContaoConshoBundle\Security\PaymentMethodDeleteVoter;
use Webwerkwien\ContaoConshoBundle\Security\ShippingMethodDeleteVoter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Order 1: shipping method 3, payment method 7.
 */
class MethodInUseVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shippingMethod' => 3, 'paymentMethod' => 7]);
    }

    public function testEineVersandartAusEinerBestellungIstNichtLoeschbar(): void
    {
        // The order refers to it.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ShippingMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_shipping_method', ['id' => '3'])));
    }

    public function testEineUngenutzteVersandartIstLoeschbar(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new ShippingMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_shipping_method', ['id' => '4'])));
    }

    public function testJedeArtPrueftIhreEigeneSpalte(): void
    {
        // Payment method 3 appears in no order — only shipping method 3 does.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new PaymentMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_payment_method', ['id' => '3'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new PaymentMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_payment_method', ['id' => '7'])));
    }

    public function testBearbeitenUndLesenBleibenErlaubt(): void
    {
        // An old method is unpublished, not deleted.
        $voter = new ShippingMethodDeleteVoter(new CatalogReferences($this->connection));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_shipping_method', ['id' => '3'], ['published' => false])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new ReadAction('tl_consho_shipping_method', ['id' => '3'])));
    }

    public function testAndereTabellenGehenDenVoterNichtsAn(): void
    {
        $voter = new ShippingMethodDeleteVoter(new CatalogReferences($this->connection));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->createMock(TokenInterface::class), new DeleteAction('tl_content', ['id' => '3']), [ContaoCorePermissions::DC_PREFIX.'tl_content']));
    }

    public function testUnterstuetztNurDeleteAction(): void
    {
        // CacheableVoterInterface: only DeleteAction triggers hasAccess(), so the
        // voter is not even asked for create/read/update.
        $voter = new ShippingMethodDeleteVoter(new CatalogReferences($this->connection));

        self::assertTrue($voter->supportsType(DeleteAction::class));
        self::assertFalse($voter->supportsType(UpdateAction::class));
    }

    public function testDieMemoisierungGiltBisZumReset(): void
    {
        // Shipping method 4 is unused at first.
        $voter = new ShippingMethodDeleteVoter(new CatalogReferences($this->connection));
        $action = new DeleteAction('tl_consho_shipping_method', ['id' => '4']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $action));

        // Only afterwards is an order added — the remembered answer stays.
        // Numbered: orderNumber is unique in the DCA, as every real order has one.
        $this->connection->insert('tl_consho_order', ['id' => 2, 'orderNumber' => 'WEB-2', 'shippingMethod' => 4, 'paymentMethod' => 0]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $action));

        $voter->reset();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, $action));
    }

    private function vote(CatalogInUseVoter $voter, DeleteAction|ReadAction|UpdateAction $action): int
    {
        return $voter->vote($this->createMock(TokenInterface::class), $action, [ContaoCorePermissions::DC_PREFIX.$action->getDataSource()]);
    }
}

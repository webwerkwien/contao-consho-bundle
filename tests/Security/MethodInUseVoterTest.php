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
 * Bestellung 1: Versandart 3, Zahlungsart 7.
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
        // Recherche, Folgerung 5: Die Bestellung verweist auf sie.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new ShippingMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_shipping_method', ['id' => '3'])));
    }

    public function testEineUngenutzteVersandartIstLoeschbar(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new ShippingMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_shipping_method', ['id' => '4'])));
    }

    public function testJedeArtPrueftIhreEigeneSpalte(): void
    {
        // Zahlungsart 3 kommt in keiner Bestellung vor — nur Versandart 3.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new PaymentMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_payment_method', ['id' => '3'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new PaymentMethodDeleteVoter(new CatalogReferences($this->connection)), new DeleteAction('tl_consho_payment_method', ['id' => '7'])));
    }

    public function testBearbeitenUndLesenBleibenErlaubt(): void
    {
        // Eine alte Art wird unveröffentlicht, nicht gelöscht.
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
        // CacheableVoterInterface: nur DeleteAction löst hasAccess() aus, damit der
        // Voter für Create/Read/Update gar nicht erst befragt wird.
        $voter = new ShippingMethodDeleteVoter(new CatalogReferences($this->connection));

        self::assertTrue($voter->supportsType(DeleteAction::class));
        self::assertFalse($voter->supportsType(UpdateAction::class));
    }

    public function testDieMemoisierungGiltBisZumReset(): void
    {
        // Versandart 4 ist zu Beginn ungenutzt.
        $voter = new ShippingMethodDeleteVoter(new CatalogReferences($this->connection));
        $action = new DeleteAction('tl_consho_shipping_method', ['id' => '4']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, $action));

        // Erst danach kommt eine Bestellung dazu — die gemerkte Antwort bleibt stehen.
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

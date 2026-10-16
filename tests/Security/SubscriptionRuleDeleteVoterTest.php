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
use Webwerkwien\ContaoConshoBundle\Security\SubscriptionRuleDeleteVoter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Regel 5 hat einen Preis, Regel 6 nur eine nie gespeicherte Preiszeile,
 * Regel 7 steht nur in der Auswahl eines Produkts.
 */
class SubscriptionRuleDeleteVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_price');
        DcaSchema::create($this->connection, 'tl_consho_product');

        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'subscriptionRule' => 5]);
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 0, 'subscriptionRule' => 6]);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'subscriptionRules' => serialize(['7'])]);
    }

    public function testEineRegelMitPreisIstNichtLoeschbar(): void
    {
        // Eine Preiszeile ohne Regel wäre ein Preis ohne Vertrag.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new DeleteAction('tl_consho_subscription_rule', ['id' => '5'])));
    }

    public function testEineNieGespeichertePreiszeileHaeltNichtFest(): void
    {
        // tstamp 0: die Preismaske wurde geöffnet, aber nie gespeichert.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new DeleteAction('tl_consho_subscription_rule', ['id' => '6'])));
    }

    public function testDieAuswahlAmProduktHaeltNichtFest(): void
    {
        // Eine gelöschte Regel fällt beim Lesen aus der Auswahl (SaleModes).
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new DeleteAction('tl_consho_subscription_rule', ['id' => '7'])));
    }

    public function testBearbeitenUndLesenBleibenErlaubt(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new UpdateAction('tl_consho_subscription_rule', ['id' => '5'], ['title' => 'Neu'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new ReadAction('tl_consho_subscription_rule', ['id' => '5'])));
    }

    public function testDieMemoisierungGiltBisZumReset(): void
    {
        $voter = new SubscriptionRuleDeleteVoter(new CatalogReferences($this->connection));
        $action = new DeleteAction('tl_consho_subscription_rule', ['id' => '7']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voteWith($voter, $action));

        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'subscriptionRule' => 7]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voteWith($voter, $action));

        $voter->reset();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voteWith($voter, $action));
    }

    private function vote(DeleteAction|ReadAction|UpdateAction $action): int
    {
        return $this->voteWith(new SubscriptionRuleDeleteVoter(new CatalogReferences($this->connection)), $action);
    }

    private function voteWith(SubscriptionRuleDeleteVoter $voter, DeleteAction|ReadAction|UpdateAction $action): int
    {
        return $voter->vote($this->createMock(TokenInterface::class), $action, [ContaoCorePermissions::DC_PREFIX.$action->getDataSource()]);
    }
}

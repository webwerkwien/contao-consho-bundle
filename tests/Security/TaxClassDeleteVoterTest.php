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
use Webwerkwien\ContaoConshoBundle\Security\TaxClassDeleteVoter;
use Webwerkwien\ContaoConshoBundle\Tax\TaxClassReferences;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Subscription\SubscriptionSchema;

/**
 * Steuerklasse 1 hängt an einem Produkt, Steuerklasse 2 an einer Versandart,
 * Steuerklasse 3 an nichts. Klasse 9 hat nur Sätze — die sind Kindzeilen.
 */
class TaxClassDeleteVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['tl_consho_tax_class', 'tl_consho_tax_rate', 'tl_consho_product', 'tl_consho_shipping_method', 'tl_consho_payment_method'] as $table) {
            DcaSchema::create($this->connection, $table);
        }
        SubscriptionSchema::create($this->connection);

        foreach ([1, 2, 3, 9] as $id) {
            $this->connection->insert('tl_consho_tax_class', ['id' => $id, 'title' => 'Klasse '.$id]);
        }

        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Wanderstock', 'published' => 1, 'taxClass' => 1]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'published' => 1, 'taxClass' => 2]);
        $this->connection->insert('tl_consho_tax_rate', ['id' => 40, 'pid' => 9]);
    }

    public function testEineKlasseAnEinemProduktIstNichtLoeschbar(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new DeleteAction('tl_consho_tax_class', ['id' => '1'])));
    }

    public function testEineKlasseAnEinerVersandartIstNichtLoeschbar(): void
    {
        /*
         * Das war der Punkt aus C-6, der C-7 überlebt hat. Er steht hier
         * neben dem Produkt, weil ein Schutz, der nur die Versandart kennt,
         * die halbe Antwort wäre.
         */
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new DeleteAction('tl_consho_tax_class', ['id' => '2'])));
    }

    public function testEineUngenutzteKlasseIstLoeschbar(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new DeleteAction('tl_consho_tax_class', ['id' => '3'])));
    }

    public function testEigeneSaetzeSindKeinGrundZuVerweigern(): void
    {
        // tl_consho_tax_rate ist Kindtabelle (ctable): Die Sätze gehen mit in
        // den Papierkorb, sie halten die Klasse nicht fest.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new DeleteAction('tl_consho_tax_class', ['id' => '9'])));
    }

    public function testAuchEinUnveroeffentlichtesProduktHaeltDieKlasseFest(): void
    {
        /*
         * Es wird wieder veröffentlicht, und dann wäre die Klasse weg. Der
         * Schaden entsteht ohnehin erst beim Anzeigen des Preises — der
         * Verweis ist schon vorher tot.
         */
        $this->connection->insert('tl_consho_product', ['id' => 7, 'title' => 'Entwurf', 'published' => 0, 'taxClass' => 3]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new DeleteAction('tl_consho_tax_class', ['id' => '3'])));
    }

    public function testBearbeitenUndLesenBleibenErlaubt(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new UpdateAction('tl_consho_tax_class', ['id' => '1'], ['title' => 'Neu'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new ReadAction('tl_consho_tax_class', ['id' => '1'])));
    }

    public function testAndereTabellenGehenDenVoterNichtsAn(): void
    {
        $voter = new TaxClassDeleteVoter(new TaxClassReferences($this->connection));

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->createMock(TokenInterface::class), new DeleteAction('tl_consho_shipping_method', ['id' => '6']), [ContaoCorePermissions::DC_PREFIX.'tl_consho_shipping_method']),
        );
    }

    public function testUnterstuetztNurDeleteAction(): void
    {
        $voter = new TaxClassDeleteVoter(new TaxClassReferences($this->connection));

        self::assertTrue($voter->supportsType(DeleteAction::class));
        self::assertFalse($voter->supportsType(UpdateAction::class));
    }

    public function testDieMemoisierungGiltBisZumReset(): void
    {
        $voter = new TaxClassDeleteVoter(new TaxClassReferences($this->connection));
        $action = new DeleteAction('tl_consho_tax_class', ['id' => '3']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voteWith($voter, $action));

        $this->connection->insert('tl_consho_product', ['id' => 8, 'title' => 'Neu', 'published' => 1, 'taxClass' => 3]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voteWith($voter, $action));

        $voter->reset();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voteWith($voter, $action));
    }

    private function vote(DeleteAction|ReadAction|UpdateAction $action): int
    {
        return $this->voteWith(new TaxClassDeleteVoter(new TaxClassReferences($this->connection)), $action);
    }

    private function voteWith(TaxClassDeleteVoter $voter, DeleteAction|ReadAction|UpdateAction $action): int
    {
        return $voter->vote($this->createMock(TokenInterface::class), $action, [ContaoCorePermissions::DC_PREFIX.$action->getDataSource()]);
    }
}

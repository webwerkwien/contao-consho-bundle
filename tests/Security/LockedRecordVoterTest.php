<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\AbstractAction;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoConshoBundle\Security\DeliveryItemLockVoter;
use Webwerkwien\ContaoConshoBundle\Security\DeliveryLockVoter;
use Webwerkwien\ContaoConshoBundle\Security\PaymentLockVoter;

/**
 * Gesperrte Zahlungen, Lieferungen und ihre Positionen (D-1, A2; Review S2, S3).
 */
final class LockedRecordVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_delivery');

        $this->connection->insert('tl_consho_payment', ['id' => 1, 'lockedAt' => 100]);
        $this->connection->insert('tl_consho_payment', ['id' => 2]);
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'lockedAt' => 100]);
        $this->connection->insert('tl_consho_delivery', ['id' => 2]);
    }

    public function testEineGesperrteZahlungIstNichtLoeschbar(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new PaymentLockVoter($this->connection), new DeleteAction('tl_consho_payment', ['id' => '1'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(new PaymentLockVoter($this->connection), new DeleteAction('tl_consho_payment', ['id' => '2'])));
    }

    /**
     * Öffnen und das Speichern der schreibgeschützten Maske bringen nichts
     * Neues — beides bleibt erlaubt, sonst würde das Neuladen nach dem
     * sperrenden Speichern zur Fehlerseite.
     */
    public function testOeffnenUndSpeichernOhneNeueWerteBleibenErlaubt(): void
    {
        $voter = new PaymentLockVoter($this->connection);
        $current = ['id' => '1', 'amount' => '40.0000', 'tstamp' => '100'];

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_payment', $current)));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_payment', $current, ['tstamp' => 200])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_payment', $current, ['amount' => '40.0000'])));
    }

    /**
     * Review S2: „Mehrere bearbeiten" schickt neue Werte, die schreibgeschützte
     * Maske deckt nur act=edit ab.
     */
    public function testNeueWerteFuerEineGesperrteZahlungWerdenVerweigert(): void
    {
        $voter = new PaymentLockVoter($this->connection);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new UpdateAction('tl_consho_payment', ['id' => '1', 'amount' => '40.0000'], ['amount' => '4.00'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_payment', ['id' => '2', 'amount' => '40.0000'], ['amount' => '4.00'])));
    }

    /**
     * Review 2, S-c: In „Mehrere bearbeiten" fällt ein gesperrter Satz aus
     * der Maske, statt editierbar zu erscheinen und beim Speichern still
     * übersprungen zu werden.
     */
    public function testInMehrereBearbeitenIstEinGesperrterSatzNichtDabei(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => 'editAll']));
        $voter = new PaymentLockVoter($this->connection, $requests);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new UpdateAction('tl_consho_payment', ['id' => '1'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_payment', ['id' => '2'])));
    }

    public function testZahlenWerdenAlsZahlenVerglichen(): void
    {
        $voter = new PaymentLockVoter($this->connection);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_payment', ['id' => '1', 'amount' => '40.0000'], ['amount' => '40'])));
    }

    public function testEineGemeldeteLieferungIstNichtLoeschbar(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(new DeliveryLockVoter($this->connection), new DeleteAction('tl_consho_delivery', ['id' => '1'])));
    }

    public function testInEinGemeldetesPaketKommtNichtsHinzu(): void
    {
        $voter = new DeliveryItemLockVoter($this->connection);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new CreateAction('tl_consho_delivery_item', ['pid' => '1'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new CreateAction('tl_consho_delivery_item', ['pid' => '2'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new DeleteAction('tl_consho_delivery_item', ['id' => '5', 'pid' => '1'])));
    }

    /**
     * Review S3: DC_Table::cut() fragt UpdateAction mit dem neuen pid — die
     * Position darf nicht in ein gemeldetes Paket wandern.
     */
    public function testVerschiebenInEinGemeldetesPaketWirdVerweigert(): void
    {
        $voter = new DeliveryItemLockVoter($this->connection);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new UpdateAction('tl_consho_delivery_item', ['id' => '5', 'pid' => '2'], ['pid' => '1'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_delivery_item', ['id' => '5', 'pid' => '2'], ['quantity' => '1'])));
    }

    private function vote(VoterInterface $voter, AbstractAction $action): int
    {
        return $voter->vote($this->createStub(TokenInterface::class), $action, [ContaoCorePermissions::DC_PREFIX.$action->getDataSource()]);
    }
}

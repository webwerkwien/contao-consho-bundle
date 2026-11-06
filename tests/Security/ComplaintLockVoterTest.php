<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoConshoBundle\Security\ComplaintItemLockVoter;
use Webwerkwien\ContaoConshoBundle\Security\ComplaintLockVoter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Reklamation, P4 and RK-4: complaint 1 is decided, complaint 2 not.
 */
final class ComplaintLockVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_complaint');
        DcaSchema::create($this->connection, 'tl_consho_complaint_item');

        $this->connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => 7, 'number' => 'R1', 'remedy' => 'rescission', 'decidedAt' => 100, 'state' => 'inProgress']);
        $this->connection->insert('tl_consho_complaint', ['id' => 2, 'pid' => 7, 'number' => 'R2']);
    }

    public function testNurEineUnentschiedeneReklamationIstLoeschbar(): void
    {
        $voter = new ComplaintLockVoter($this->connection);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new DeleteAction('tl_consho_complaint', ['id' => '1', 'pid' => '7'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new DeleteAction('tl_consho_complaint', ['id' => '2', 'pid' => '7'])));

        // The note stays editable.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new UpdateAction('tl_consho_complaint', ['id' => '1', 'pid' => '7'], ['note' => 'x'])));
    }

    public function testPositionenEinerEntschiedenenReklamationSindGesperrt(): void
    {
        $voter = new ComplaintItemLockVoter($this->connection);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new CreateAction('tl_consho_complaint_item', ['pid' => '1'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new UpdateAction('tl_consho_complaint_item', ['id' => '5', 'pid' => '1'], ['quantity' => '2'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new DeleteAction('tl_consho_complaint_item', ['id' => '5', 'pid' => '1'])));
        // Moved into a decided complaint.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($voter, new UpdateAction('tl_consho_complaint_item', ['id' => '6', 'pid' => '2'], ['pid' => '1'])));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new CreateAction('tl_consho_complaint_item', ['pid' => '2'])));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($voter, new DeleteAction('tl_consho_complaint_item', ['id' => '6', 'pid' => '2'])));
    }

    private function vote(VoterInterface $voter,CreateAction|DeleteAction|UpdateAction $action): int
    {
        return $voter->vote($this->createMock(TokenInterface::class), $action, [ContaoCorePermissions::DC_PREFIX.$action->getDataSource()]);
    }
}

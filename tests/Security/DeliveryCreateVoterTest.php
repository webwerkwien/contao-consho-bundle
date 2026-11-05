<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Security;

use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Security\DeliveryCreateVoter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * "Neue Lieferung" only where a parcel can concern the order. Contao's
 * data container voters abstain where they have nothing against an action.
 */
final class DeliveryCreateVoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Kräutersalz', 'quantity' => '1', 'kind' => 'physical']);
    }

    public function testOffeneWareDarfInsPaket(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote());
    }

    public function testVorDemVersandWiderrufenKeinPaket(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 7, 'number' => 'WEB-7-W1', 'state' => 'accepted', 'decidedAt' => 100]);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '0', 'acceptedQuantity' => '1']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote());
    }

    public function testStorniertKeinPaket(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 100], ['id' => 7]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote());
    }

    public function testNurDienstleistungKeinPaket(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 1]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote());
    }

    /**
     * Shipped in full: a replacement may still have to follow.
     */
    public function testNachDemVersandBleibtEinErsatzMoeglich(): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 3, 'pid' => 7, 'reportedAt' => 100]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 3, 'orderItem' => 1, 'quantity' => '1']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote());
    }

    private function vote(): int
    {
        $contents = new DeliveryContents($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));

        return (new DeliveryCreateVoter($contents))->vote(
            $this->createStub(TokenInterface::class),
            new CreateAction('tl_consho_delivery', ['pid' => 7]),
            [ContaoCorePermissions::DC_PREFIX.'tl_consho_delivery'],
        );
    }
}

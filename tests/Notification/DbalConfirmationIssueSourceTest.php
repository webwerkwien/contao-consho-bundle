<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Notification\DbalConfirmationIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalConfirmationIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_mail_transport');

        $this->connection->insert('tl_consho_mail_transport', ['id' => 1, 'senderAddress' => 'shop@example.org']);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'operatorAddress' => 'office@example.org', 'mailTransport' => 1]);
    }

    public function testVersendeteUndAltbestandMeldenNichts(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'orderNumber' => 'WEB-1', 'confirmationSentAt' => 1_758_800_000]);
        // 0: created before C-9, no confirmation due.
        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 1, 'orderNumber' => 'WEB-2', 'confirmationSentAt' => 0]);

        self::assertSame([], $this->source()->issues());
    }

    public function testNichtVersendeteWerdenJeShopMitNummernGemeldet(): void
    {
        foreach (range(1, 6) as $id) {
            $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => 1, 'orderNumber' => 'WEB-'.$id]);
        }

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ConfirmationNotSent, $issues[0]->finding);
        self::assertSame(1, $issues[0]->shopId);
        self::assertSame(['shop' => 'Testshop', 'count' => '6', 'numbers' => 'WEB-1, WEB-2, WEB-3, WEB-4, WEB-5, …'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    /**
     * Microsoft 365 files a mail to oneself only under "Sent" — a notice,
     * upper or lower case.
     */
    public function testBetreiberadresseGleichAbsenderIstEinHinweis(): void
    {
        $this->connection->update('tl_consho_shop', ['operatorAddress' => ' Shop@Example.org'], ['id' => 1]);

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::OperatorAddressIsSender, $issues[0]->finding);
        self::assertSame('Shop@Example.org', $issues[0]->context['address']);
        self::assertFalse($issues[0]->finding->isWarning());
    }

    private function source(): DbalConfirmationIssueSource
    {
        return new DbalConfirmationIssueSource($this->connection);
    }
}

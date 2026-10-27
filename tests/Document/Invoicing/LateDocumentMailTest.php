<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\LateDocumentMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block R (R18): the document a retry had the module issue goes to the
 * customer of its order, with the link to the order page.
 */
final class LateDocumentMailTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'shop' => 1]);
    }

    public function testDerBelegGehtAnDieBestellungMitIhremLink(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 7, 'type' => 'cancellation']);

        $mail = $this->createMock(OrderEventMail::class);
        $mail->expects(self::once())->method('documentIssued')->with(7, 4, DocumentType::Cancellation, 'https://shop.test/x')->willReturn(true);

        self::assertTrue($this->late($mail, 'https://shop.test/x')->send(4));
    }

    public function testOhneBestellseiteGehtNichtsHinaus(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 7, 'type' => 'invoice']);

        $mail = $this->createMock(OrderEventMail::class);
        $mail->expects(self::never())->method('documentIssued');

        self::assertFalse($this->late($mail, null)->send(4));
    }

    public function testEinLieferscheinIstKeinBelegDesModuls(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 7, 'type' => 'deliveryNote']);

        $mail = $this->createMock(OrderEventMail::class);
        $mail->expects(self::never())->method('documentIssued');

        self::assertFalse($this->late($mail, 'https://shop.test/x')->send(4));
    }

    private function late(OrderEventMail $mail, string|null $url): LateDocumentMail
    {
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn($url);

        return new LateDocumentMail($this->connection, $links, $mail, new NullLogger());
    }
}

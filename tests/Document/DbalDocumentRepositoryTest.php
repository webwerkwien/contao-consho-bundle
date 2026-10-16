<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * C-10 (7.5): Eine stornierte Rechnung steht nicht mehr. Wer „die Rechnung
 * dieser Bestellung" sucht, meint die, die gilt — sonst fände issueOnce nach
 * dem Wechsel der Zahlungsart die alte und stellte keine neue aus.
 */
class DbalDocumentRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
    }

    public function testEineStornierteRechnungWirdNichtGefunden(): void
    {
        $this->document(1, 'invoice', 'RE-00001');
        $this->document(2, 'cancellation', 'ST-00001', 1);

        self::assertNull((new DbalDocumentRepository($this->connection))->findIdByOrderAndType(5, DocumentType::Invoice));
    }

    public function testNachDemStornoGiltDieNeueRechnung(): void
    {
        $this->document(1, 'invoice', 'RE-00001');
        $this->document(2, 'cancellation', 'ST-00001', 1);
        $this->document(3, 'invoice', 'RE-00002');

        self::assertSame(3, (new DbalDocumentRepository($this->connection))->findIdByOrderAndType(5, DocumentType::Invoice));
    }

    public function testEinStornoEinerAnderenBestellungZaehltNicht(): void
    {
        $this->document(1, 'invoice', 'RE-00001');
        $this->connection->insert('tl_consho_document', ['id' => 2, 'pid' => 6, 'type' => 'cancellation', 'documentNumber' => 'ST-00001', 'relatedDocument' => 99]);

        self::assertSame(1, (new DbalDocumentRepository($this->connection))->findIdByOrderAndType(5, DocumentType::Invoice));
    }

    public function testDerStornoZuEinerRechnung(): void
    {
        $this->document(1, 'invoice', 'RE-00001');
        $this->document(2, 'cancellation', 'ST-00001', 1);

        $repository = new DbalDocumentRepository($this->connection);

        self::assertSame(2, $repository->findCancellationOf(1));
        self::assertNull($repository->findCancellationOf(2));
    }

    public function testEinBelegMitSchnappschuss(): void
    {
        $this->document(1, 'invoice', 'RE-00001');

        $row = (new DbalDocumentRepository($this->connection))->findDocumentRow(1);

        self::assertSame('RE-00001', $row['documentNumber'] ?? null);
        self::assertSame('{}', $row['payload'] ?? null);
        self::assertNull((new DbalDocumentRepository($this->connection))->findDocumentRow(9));
    }

    private function document(int $id, string $type, string $number, int $related = 0): void
    {
        $this->connection->insert('tl_consho_document', [
            'id' => $id, 'pid' => 5, 'shop' => 3, 'type' => $type, 'documentNumber' => $number, 'relatedDocument' => $related, 'payload' => '{}',
        ]);
    }
}

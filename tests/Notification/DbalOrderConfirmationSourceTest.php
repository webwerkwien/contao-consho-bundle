<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Notification\DbalOrderConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalOrderConfirmationSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_order_legal', 'tl_consho_document', 'tl_consho_shop', 'tl_consho_mail_transport'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7']);
    }

    /**
     * Nach einem Wechsel der Zahlungsart (C-10) trägt eine Bestellung eine
     * stornierte und eine neue Rechnung. Angehängt wird die, die gilt.
     */
    public function testDieStornierteRechnungWirdUebergangen(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 1, 'pid' => 7, 'type' => 'invoice', 'documentNumber' => 'RE-1', 'payload' => '{"payment":{"instructions":"alt"}}']);
        $this->connection->insert('tl_consho_document', ['id' => 2, 'pid' => 7, 'type' => 'cancellation', 'documentNumber' => 'ST-1', 'relatedDocument' => 1]);
        $this->connection->insert('tl_consho_document', ['id' => 3, 'pid' => 7, 'type' => 'invoice', 'documentNumber' => 'RE-2', 'payload' => '{"payment":{"instructions":"neu"}}']);

        $invoice = $this->source()->invoice(7);

        self::assertSame(3, $invoice['id'] ?? null);
        self::assertSame('neu', $invoice['payload']['payment']['instructions'] ?? null);
    }

    /**
     * C-10: Die Mail nach dem Wechsel liest Storno und neue Rechnung per Id —
     * aber nur Belege DIESER Bestellung.
     */
    public function testEinBelegWirdNurUeberSeineBestellungGefunden(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 2, 'pid' => 7, 'type' => 'cancellation', 'documentNumber' => 'ST-1', 'relatedDocument' => 1, 'payload' => '{"cancels":{"number":"RE-1"}}']);

        $source = new DbalOrderConfirmationSource($this->connection);

        self::assertSame('ST-1', $source->document(7, 2)['documentNumber'] ?? null);
        self::assertSame('RE-1', $source->document(7, 2)['payload']['cancels']['number'] ?? null);
        self::assertNull($source->document(8, 2));
    }

    public function testEineStornierteRechnungOhneNeueIstKeine(): void
    {
        $this->connection->insert('tl_consho_document', ['id' => 1, 'pid' => 7, 'type' => 'invoice', 'documentNumber' => 'RE-1']);
        $this->connection->insert('tl_consho_document', ['id' => 2, 'pid' => 7, 'type' => 'cancellation', 'documentNumber' => 'ST-1', 'relatedDocument' => 1]);

        self::assertNull($this->source()->invoice(7));
    }

    public function testRechtstexteInDerReihenfolgeDesEinfrierens(): void
    {
        $this->connection->insert('tl_consho_order_legal', ['id' => 5, 'pid' => 7, 'kind' => 'terms', 'headline' => 'AGB', 'text' => '<p>a</p>']);
        $this->connection->insert('tl_consho_order_legal', ['id' => 6, 'pid' => 7, 'kind' => 'withdrawal', 'headline' => 'Widerruf', 'text' => '<p>b</p>']);
        $this->connection->insert('tl_consho_order_legal', ['id' => 9, 'pid' => 8, 'kind' => 'terms', 'headline' => 'fremd', 'text' => '']);

        self::assertSame(
            [['headline' => 'AGB', 'text' => '<p>a</p>'], ['headline' => 'Widerruf', 'text' => '<p>b</p>']],
            $this->source()->legalTexts(7),
        );
    }

    public function testDerShopBringtSeineMailKonfigurationMit(): void
    {
        $this->connection->insert('tl_consho_mail_transport', ['id' => 2, 'type' => 'smtp', 'senderAddress' => 'shop@example.org']);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'operatorAddress' => 'office@example.org', 'fallbackLanguage' => 'de', 'mailTransport' => 2]);
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'mailTransport' => 0]);

        self::assertSame('shop@example.org', $this->source()->shop(1)['transport']['senderAddress'] ?? null);
        self::assertNull($this->source()->shop(3)['transport'] ?? null);
        self::assertNull($this->source()->shop(99));
    }

    /**
     * Der Name der Betreiberkopie steht im Mail-Kopf, und ein Kopf ist kein
     * HTML: Contaos Kodierung („Kräuter &amp; Co") muss vorher weg.
     */
    public function testDerBetreibernameKommtDekodiert(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'operatorName' => 'Kräuter &amp; Co &#40;Wien&#41;']);

        self::assertSame('Kräuter & Co (Wien)', $this->source()->shop(1)['operatorName'] ?? null);
    }

    public function testMarkSentSchreibtDenZeitpunkt(): void
    {
        $this->source()->markSent(7, 1_758_800_000);

        self::assertSame(1_758_800_000, (int) $this->connection->fetchOne('SELECT confirmationSentAt FROM tl_consho_order WHERE id = 7'));
    }

    public function testDerStartpunktFolgtDerSpracheDerBestellung(): void
    {
        $this->connection->executeStatement("CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT '', consho_shop INTEGER NOT NULL DEFAULT 0, language TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'consho_shop' => 1, 'language' => 'de']);
        $this->connection->insert('tl_page', ['id' => 5, 'type' => 'root', 'consho_shop' => 1, 'language' => 'en']);
        $this->connection->insert('tl_page', ['id' => 6, 'type' => 'regular', 'consho_shop' => 1, 'language' => 'en']);
        $this->connection->insert('tl_page', ['id' => 8, 'type' => 'root', 'consho_shop' => 3, 'language' => 'en']);

        self::assertSame(5, $this->source()->rootOf(1, 'en'));
        self::assertSame(2, $this->source()->rootOf(1, 'de'));
        // Keine passende Sprache: irgendein Startpunkt des Shops, stets derselbe.
        self::assertSame(2, $this->source()->rootOf(1, 'fr'));
        self::assertNull($this->source()->rootOf(9, 'de'));
    }

    private function source(): DbalOrderConfirmationSource
    {
        return new DbalOrderConfirmationSource($this->connection);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalLegalTextSource;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalTextKind;

class DbalLegalTextSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            'CREATE TABLE tl_page (id INTEGER PRIMARY KEY, consho_termsElement INTEGER NOT NULL DEFAULT 0, consho_withdrawalElement INTEGER NOT NULL DEFAULT 0)',
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 1, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT 'tl_article', type TEXT NOT NULL DEFAULT 'text', invisible INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '', headline TEXT NOT NULL DEFAULT '', text TEXT NOT NULL DEFAULT '', singleSRC TEXT NOT NULL DEFAULT '')",
        );

        $this->connection->insert('tl_page', ['id' => 1, 'consho_termsElement' => 10, 'consho_withdrawalElement' => 11]);
        $this->connection->insert('tl_page', ['id' => 5]);
        $this->connection->insert('tl_article', ['id' => 2, 'pid' => 5]);

        $this->connection->insert('tl_content', [
            'id' => 10, 'pid' => 2, 'type' => 'text',
            'headline' => serialize(['unit' => 'h2', 'value' => 'AGB']),
            'text' => '<p>Es gelten diese Bedingungen.</p>',
        ]);
        $this->connection->insert('tl_content', [
            'id' => 11, 'pid' => 2, 'type' => 'text',
            'headline' => serialize(['unit' => 'h2', 'value' => 'Widerruf']),
            'text' => '<p>Sie können binnen 14 Tagen widerrufen.</p>',
        ]);
    }

    public function testBeideTexteWerdenGelesen(): void
    {
        $texts = $this->source()->forRoot(1);

        self::assertSame(['terms', 'withdrawal'], array_keys($texts));
        self::assertSame('AGB', $texts['terms']->headline);
        self::assertSame(5, $texts['terms']->pageId);
        self::assertSame(10, $texts['terms']->elementId);
    }

    /**
     * The headline is stored serialised as `['unit' => 'h2', 'value' => …]`.
     * Only the value is legal text — the level is presentation, and with it in
     * the checksum a change from h2 to h3 would "change" the text.
     */
    public function testDieUeberschriftKommtOhneEbene(): void
    {
        self::assertSame('AGB', $this->source()->one(1, LegalTextKind::Terms)?->headline);
    }

    /**
     * ⚠️ C17: an image in the element stays out. `headline` and `text` are
     * read, nothing else — the column next to them does not touch the source.
     */
    public function testEinBildImElementWirdNichtGelesen(): void
    {
        $this->connection->update('tl_content', ['singleSRC' => 'ein-bild.jpg'], ['id' => 10]);

        $text = $this->source()->one(1, LegalTextKind::Terms);

        self::assertNotNull($text);
        self::assertStringNotContainsString('ein-bild', $text->text);
    }

    public function testEinUnsichtbaresElementZaehltNicht(): void
    {
        $this->connection->update('tl_content', ['invisible' => 1], ['id' => 10]);

        self::assertNull($this->source()->one(1, LegalTextKind::Terms));
    }

    public function testEinElementVomFalschenTypZaehltNicht(): void
    {
        // C17 says "only of type text". An accordion has no `text` that
        // could be frozen.
        $this->connection->update('tl_content', ['type' => 'accordion'], ['id' => 10]);

        self::assertNull($this->source()->one(1, LegalTextKind::Terms));
    }

    public function testOhneAusgewaehltesElementKommtNichts(): void
    {
        self::assertSame([], $this->source()->forRoot(5));
    }

    /**
     * 🔴 A legal text in an unpublished article was delivered. The page does
     * not show it — the customer clicks "Terms and conditions" and reads
     * nothing.
     */
    public function testEinUnveroeffentlichterArtikelZaehltNicht(): void
    {
        $this->connection->update('tl_article', ['published' => 0], ['id' => 2]);

        self::assertNull($this->source()->one(1, LegalTextKind::Terms));
    }

    public function testEinArtikelAusserhalbSeinesFenstersZaehltNicht(): void
    {
        $this->connection->update('tl_article', ['start' => (string) (time() + 86_400)], ['id' => 2]);

        self::assertNull($this->source()->one(1, LegalTextKind::Terms));
    }

    /**
     * The content element has a time window of its own — in Contao two fields
     * next to `invisible`, and the first version looked only at `invisible`.
     */
    public function testEinElementAusserhalbSeinesFenstersZaehltNicht(): void
    {
        $this->connection->update('tl_content', ['stop' => (string) (time() - 60)], ['id' => 10]);

        self::assertNull($this->source()->one(1, LegalTextKind::Terms));
    }

    public function testEinVeroeffentlichterArtikelImFensterZaehltSehrWohl(): void
    {
        // The control case: otherwise the three tests above would only check that
        // nothing ever comes out.
        $this->connection->update('tl_article', [
            'published' => 1, 'start' => (string) (time() - 86_400), 'stop' => (string) (time() + 86_400),
        ], ['id' => 2]);

        self::assertNotNull($this->source()->one(1, LegalTextKind::Terms));
    }

    /**
     * The checksum covers the RESOLVED text: what the customer read, not the
     * source it is written in.
     */
    public function testDiePruefsummeFolgtDemAufgeloestenText(): void
    {
        $vorher = $this->source()->one(1, LegalTextKind::Terms)?->checksum;

        $this->connection->update('tl_content', ['text' => '<p>Neue Bedingungen.</p>'], ['id' => 10]);

        self::assertNotSame($vorher, $this->source()->one(1, LegalTextKind::Terms)?->checksum);
    }

    public function testDieselbenTexteErgebenDieselbePruefsumme(): void
    {
        self::assertSame(
            $this->source()->one(1, LegalTextKind::Terms)?->checksum,
            $this->source()->one(1, LegalTextKind::Terms)?->checksum,
        );
    }

    /**
     * Insert tags are resolved before the checksum is built. A
     * `{{link_url::42}}` that suddenly points elsewhere IS a change to what was
     * agreed — and must therefore stand out.
     */
    public function testInsertTagsWerdenAufgeloest(): void
    {
        $parser = $this->createMock(InsertTagParser::class);
        $parser->method('replaceInline')->willReturnCallback(
            static fn (string $value): string => str_replace('{{link_url::42}}', '/agb-anhang', $value),
        );

        $this->connection->update('tl_content', ['text' => '<p>Siehe {{link_url::42}}</p>'], ['id' => 10]);

        $text = (new DbalLegalTextSource($this->connection, $parser))->one(1, LegalTextKind::Terms);

        self::assertSame('<p>Siehe /agb-anhang</p>', $text?->text);
    }

    private function source(): DbalLegalTextSource
    {
        $parser = $this->createMock(InsertTagParser::class);
        $parser->method('replaceInline')->willReturnArgument(0);

        return new DbalLegalTextSource($this->connection, $parser);
    }
}

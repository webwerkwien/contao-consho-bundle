<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Der Join wird als Zeichenkette gebaut, aber gegen eine echte Datenbank
 * geprüft. Eine Behauptung über den Wortlaut des SQL misst nichts: Die Frage
 * ist, welcher Text herauskommt.
 */
class TranslationJoinTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($this->connection, 'tl_consho_product');

        DcaSchema::create($this->connection, 'tl_consho_translation');

        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Kräuterbitter', 'alias' => 'kraeuterbitter']);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'title' => 'Honig', 'alias' => 'honig']);
    }

    public function testWithoutATranslationTheRecordsOwnTextIsUsed(): void
    {
        self::assertSame('Kräuterbitter', $this->titleOf(1, 'en'));
    }

    public function testATranslationReplacesTheRecordsOwnText(): void
    {
        $this->translate(1, 'en', 'Herbal Bitters');

        self::assertSame('Herbal Bitters', $this->titleOf(1, 'en'));
    }

    public function testATranslationOfAnotherLanguageIsNotUsed(): void
    {
        $this->translate(1, 'fr', 'Amer aux herbes');

        self::assertSame('Kräuterbitter', $this->titleOf(1, 'en'));
    }

    public function testATranslationOfAnotherRecordIsNotUsed(): void
    {
        $this->translate(2, 'en', 'Honey');

        self::assertSame('Kräuterbitter', $this->titleOf(1, 'en'));
    }

    public function testAnEmptyTranslatedFieldFallsBackToTheRecordsOwnText(): void
    {
        // Die Zeile existiert, das Feld ist leer — etwa weil nur der Alias
        // gepflegt wurde. Der Rückfall gilt je FELD, nicht je Zeile.
        $this->translate(1, 'en', '');

        self::assertSame('Kräuterbitter', $this->titleOf(1, 'en'));
    }

    public function testInTheFallbackLanguageTheRecordsOwnTextIsUsed(): void
    {
        $this->translate(1, 'en', 'Herbal Bitters');

        self::assertSame('Kräuterbitter', $this->titleOf(1, 'de'));
    }

    public function testInTheFallbackLanguageNoJoinIsBuilt(): void
    {
        // Der Prüfstein der ganzen Mehrsprachigkeit: Ein einsprachiger Shop
        // bekommt exakt die Abfrage von vorher.
        $join = new TranslationJoin('tl_consho_product', 'de', 'de', 'p', 't');

        self::assertSame('', $join->sql());
        self::assertSame([], $join->params());
        self::assertSame('p.title', $join->coalesce('title'));
    }

    /**
     * Mehrsprachigkeit, offene Punkte (Regionalcodes): Fällt `de-AT` auf `de`
     * zurück oder auf die Standardsprache? Auf die Standardsprache — der
     * Rückfall ist einstufig, `de-AT` ist eine Sprache für sich. Hier ist die
     * Standardsprache Englisch, damit man den Unterschied sieht.
     */
    public function testEineRegionalspracheFaelltAufDieStandardspracheZurueckNichtAufIhreHauptsprache(): void
    {
        $this->connection->update('tl_consho_product', ['title' => 'Herbal Bitters'], ['id' => 1]);
        $this->translate(1, 'de', 'Kräuterbitter');

        self::assertSame('Herbal Bitters', $this->titleOf(1, 'de-AT', 'en'));

        $this->translate(1, 'de-AT', 'Kräuterbitter (AT)');
        self::assertSame('Kräuterbitter (AT)', $this->titleOf(1, 'de-AT', 'en'), 'eine eigene Übersetzung gilt');
    }

    public function testTheJoinDoesNotMultiplyRows(): void
    {
        // Ein LEFT JOIN auf eine Tabelle mit mehreren Zeilen je Produkt
        // vervielfacht das Ergebnis, wenn die Sprachbedingung fehlt. Die
        // Facettenzählungen wären dann zu hoch — und sähen plausibel aus.
        $this->translate(1, 'en', 'Herbal Bitters');
        $this->translate(1, 'fr', 'Amer aux herbes');

        $join = new TranslationJoin('tl_consho_product', 'en', 'de', 'p', 't');
        $sql = 'SELECT COUNT(*) FROM tl_consho_product p '.$join->sql();

        self::assertSame(2, (int) $this->connection->fetchOne($sql, $join->params()));
    }

    private function titleOf(int $id, string $language, string $fallback = 'de'): string
    {
        $join = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 't');

        $sql = 'SELECT '.$join->coalesce('title')
            .' FROM tl_consho_product p '
            .$join->sql()
            .' WHERE p.id = :id';

        return (string) $this->connection->fetchOne(
            $sql,
            [...$join->params(), 'id' => $id],
        );
    }

    private function translate(int $pid, string $language, string $title): void
    {
        $this->connection->insert('tl_consho_translation', [
            'pid' => $pid,
            'ptable' => 'tl_consho_product',
            'language' => $language,
            'title' => $title,
        ]);
    }
}

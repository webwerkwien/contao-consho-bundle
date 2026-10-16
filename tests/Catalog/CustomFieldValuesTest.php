<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomField;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldCodec;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldValues;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Zusatzfeld-Werte, ZW-1 (Z2; Michael, 2026-10-14: „ja, alles wie
 * empfohlen"): welche Felder ein Produkt hat, wie Werte geprüft und
 * gespeichert werden, und dass eine Variante erbt und einzeln überschreibt.
 */
class CustomFieldValuesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_custom_field'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_custom_field', ['id' => 1, 'alias' => 'jahrgang', 'title' => 'Jahrgang', 'type' => 'number']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 2, 'alias' => 'rebsorte', 'title' => 'Rebsorte', 'type' => 'text']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 3, 'alias' => 'bio', 'title' => 'Bio', 'type' => 'boolean']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 4, 'alias' => 'abfuellung', 'title' => 'Abfüllung', 'type' => 'date']);
        $this->connection->insert('tl_consho_custom_field', ['id' => 5, 'alias' => 'glasform', 'title' => 'Glasform', 'type' => 'text']);

        // Die Reihenfolge des Typs zählt, nicht die der Definitionen.
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'title' => 'Wein', 'customFields' => serialize(['2', '1', '3', '4'])]);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'title' => 'Glas', 'customFields' => serialize(['5'])]);

        $this->connection->insert('tl_consho_product', ['id' => 10, 'pid' => 0, 'productType' => 1, 'customFieldValues' => '{"rebsorte":"Grüner Veltliner","jahrgang":"2021","bio":"1","glasform":"Tulpe"}']);
        // Die Variante hat keinen eigenen Typ — sie gilt als Wein über ihr Hauptprodukt.
        $this->connection->insert('tl_consho_product', ['id' => 11, 'pid' => 10, 'productType' => 0, 'customFieldValues' => '{"jahrgang":"2022"}']);
        $this->connection->insert('tl_consho_product', ['id' => 20, 'pid' => 0, 'productType' => 0]);
    }

    public function testFelderInDerReihenfolgeDesTyps(): void
    {
        self::assertSame(['rebsorte', 'jahrgang', 'bio', 'abfuellung'], array_map(static fn (CustomField $f): string => $f->alias, $this->values()->fieldsOf(10)));
        self::assertSame(['rebsorte', 'jahrgang', 'bio', 'abfuellung'], array_map(static fn (CustomField $f): string => $f->alias, $this->values()->fieldsOf(11)), 'die Variante über ihr Hauptprodukt');
        self::assertSame([], $this->values()->fieldsOf(20), 'ohne Typ keine Felder');
        self::assertSame([], $this->values()->fieldsOf(99));
    }

    /**
     * ZW-5: Ein Befehl prüft die Werte eines Produkts, das es noch nicht gibt.
     */
    public function testFelderEinesTyps(): void
    {
        self::assertSame(['glasform'], array_map(static fn (CustomField $f): string => $f->alias, $this->values()->fieldsOfType(2)));
        self::assertSame([], $this->values()->fieldsOfType(0));
        self::assertSame([], $this->values()->fieldsOfType(99));
    }

    public function testEinGeloeschtesFeldFaelltWeg(): void
    {
        $this->connection->delete('tl_consho_custom_field', ['id' => 1]);

        self::assertSame(['rebsorte', 'bio', 'abfuellung'], array_map(static fn (CustomField $f): string => $f->alias, $this->values()->fieldsOf(10)));
    }

    /**
     * Z2: Die Variante erbt jeden Wert und überschreibt einzeln. Ein Wert
     * eines Felds, das der Typ nicht (mehr) hat, zählt nicht.
     */
    public function testDieVarianteErbtUndUeberschreibt(): void
    {
        self::assertSame(['rebsorte' => 'Grüner Veltliner', 'jahrgang' => '2021', 'bio' => '1'], $this->values()->valuesOf(10));
        self::assertSame(['rebsorte' => 'Grüner Veltliner', 'jahrgang' => '2022', 'bio' => '1'], $this->values()->valuesOf(11));
        self::assertSame(['jahrgang' => '2022'], $this->values()->ownValues(11));
    }

    public function testZusammenfuehrenUndLeerEntfernt(): void
    {
        $values = $this->values();
        $merged = $values->merge($values->ownValues(10), $values->fieldsOf(10), ['jahrgang' => ' 2023 ', 'bio' => '', 'abfuellung' => '2024-03-01']);

        self::assertSame(['rebsorte' => 'Grüner Veltliner', 'jahrgang' => '2023', 'glasform' => 'Tulpe', 'abfuellung' => '2024-03-01'], $merged);
        self::assertSame('{"rebsorte":"Grüner Veltliner","jahrgang":"2023","glasform":"Tulpe","abfuellung":"2024-03-01"}', $values->column($merged));
        self::assertNull($values->column([]), 'keine Werte: NULL');
    }

    public function testEinFremdesFeldWirdAbgelehnt(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('"glasform" is no custom field of this product\'s type. Fields: rebsorte, jahrgang, bio, abfuellung.'));

        $values = $this->values();
        $values->merge([], $values->fieldsOf(10), ['glasform' => 'Tulpe']);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function gueltig(): iterable
    {
        yield 'Zahl' => [CustomField::NUMBER, '12', '12'];
        yield 'Zahl mit Komma' => [CustomField::NUMBER, '12,5', '12.5'];
        yield 'negative Zahl' => [CustomField::NUMBER, '-3.25', '-3.25'];
        yield 'Datum' => [CustomField::DATE, '2024-02-29', '2024-02-29'];
        yield 'Ja' => [CustomField::BOOLEAN, '1', '1'];
        yield 'Nein' => [CustomField::BOOLEAN, '0', '0'];
        yield 'Text getrimmt' => [CustomField::TEXT, '  Riesling  ', 'Riesling'];
        yield 'Text 255' => [CustomField::TEXT, str_repeat('ä', 255), str_repeat('ä', 255)];
        yield 'leer' => [CustomField::NUMBER, '  ', null];
    }

    #[DataProvider('gueltig')]
    public function testGueltigeEingaben(string $type, string $raw, string|null $stored): void
    {
        self::assertSame($stored, CustomFieldValues::normalize(new CustomField(1, 'feld', 'Feld', $type), $raw));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function ungueltig(): iterable
    {
        yield 'Zahl mit Einheit' => [CustomField::NUMBER, '12 %', 'feld: "12 %" is no number (e.g. 12 or 12.5).'];
        yield 'Zahl mit Tausenderpunkt' => [CustomField::NUMBER, '1.000,5', 'feld: "1.000,5" is no number (e.g. 12 or 12.5).'];
        yield 'Datum deutsch' => [CustomField::DATE, '01.03.2024', 'feld: "01.03.2024" is no date as YYYY-MM-DD.'];
        yield 'kein Tag' => [CustomField::DATE, '2023-02-29', 'feld: "2023-02-29" is no date as YYYY-MM-DD.'];
        yield 'ja als Wort' => [CustomField::BOOLEAN, 'ja', 'feld: give 1 for yes or 0 for no, empty for none.'];
        yield 'Text zu lang' => [CustomField::TEXT, str_repeat('a', 256), 'feld: the text is longer than 255 characters.'];
        // Review ZW-C, S2: Die Maske hat eine Zeile, der Befehl nicht — ein
        // Zeilenumbruch bräche die Markdown-Liste.
        yield 'Zeilenumbruch' => [CustomField::TEXT, "Grüner\n\nVeltliner", 'feld: the text holds a line break or another control character.'];
        yield 'Tabulator' => [CustomField::TEXT, "Grüner\tVeltliner", 'feld: the text holds a line break or another control character.'];
    }

    #[DataProvider('ungueltig')]
    public function testUngueltigeEingaben(string $type, string $raw, string $message): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($message));

        CustomFieldValues::normalize(new CustomField(1, 'feld', 'Feld', $type), $raw);
    }

    private function values(): CustomFieldValues
    {
        return new CustomFieldValues($this->connection, new CustomFieldCodec());
    }
}

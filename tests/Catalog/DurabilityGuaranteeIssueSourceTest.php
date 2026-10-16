<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityGuaranteeIssueSource;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block GARAN, GA7 und Review: ein Label, das den Kunden nicht erreicht.
 * Typ 1 körperlich, 2 digital.
 */
class DurabilityGuaranteeIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'title' => 'Ware', 'kind' => 'physical']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'title' => 'Download', 'kind' => 'digital']);
    }

    public function testNenntBeideHaelften(): void
    {
        $this->product(1, 'Akkuschrauber', ['durabilityYears' => 5]);
        $this->product(2, 'Wanderstock', ['durabilityLabel' => str_repeat('a', 16)]);
        $this->product(3, 'Rucksack', ['durabilityYears' => 3, 'durabilityLabel' => str_repeat('b', 16)]);
        $this->product(4, 'Seil');
        // Review 7: zwei Jahre mit Label — keine Garantie im Sinne von Z 12a.
        $this->product(5, 'Zelt', ['durabilityYears' => 2, 'durabilityLabel' => str_repeat('c', 16)]);
        // Zwei Jahre ohne Label: gar kein Fall von Z 12a, kein Befund.
        $this->product(6, 'Hering', ['durabilityYears' => 2]);

        self::assertSame(
            [
                [ConfigurationFinding::DurabilityLabelMissing, ['product' => 'Akkuschrauber', 'years' => '5']],
                [ConfigurationFinding::DurabilityYearsMissing, ['product' => 'Wanderstock', 'years' => '0']],
                [ConfigurationFinding::DurabilityYearsMissing, ['product' => 'Zelt', 'years' => '2']],
            ],
            $this->issues(),
        );
        self::assertTrue(ConfigurationFinding::DurabilityLabelMissing->isWarning());
        self::assertTrue(ConfigurationFinding::DurabilityYearsMissing->isWarning());
    }

    public function testNurVeroeffentlichteWaren(): void
    {
        // Ohne Typ ist es eine Ware (DbalArticleShippingData).
        $this->product(1, 'Ohne Typ', ['durabilityYears' => 5, 'productType' => 0]);
        $this->product(2, 'Entwurf', ['durabilityYears' => 5, 'published' => 0]);
        $this->product(3, 'E-Book', ['durabilityYears' => 5, 'productType' => 2]);

        self::assertSame(['Ohne Typ'], array_map(static fn (array $i): string => $i[1]['product'], $this->issues()));
    }

    /**
     * Review GARAN, 2: an der Variante wirkt der Wert nicht.
     */
    public function testEinWertAnDerVariante(): void
    {
        $this->product(1, 'Akkuschrauber', ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('a', 16)]);
        $this->product(2, 'Akkuschrauber blau', ['pid' => 1, 'productType' => 0, 'durabilityYears' => 4]);

        self::assertSame([[ConfigurationFinding::DurabilityOnVariant, ['product' => 'Akkuschrauber blau', 'years' => '4']]], $this->issues());
        self::assertFalse(ConfigurationFinding::DurabilityOnVariant->isWarning());
    }

    /**
     * Abnahme c5 und Review 5: nicht öffentlich, zu schwer für eine Mail.
     */
    public function testDieDateiSelbst(): void
    {
        $this->product(1, 'Geschützt', ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('p', 16)]);
        $this->product(2, 'Schwer', ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('s', 16)]);
        $this->product(3, 'Richtig', ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('r', 16)]);

        $files = $this->createMock(PublicFiles::class);
        $files->method('isPublic')->willReturnCallback(static fn (string $b): bool => str_repeat('p', 16) !== $b);
        $files->method('size')->willReturnCallback(static fn (string $b): int => str_repeat('s', 16) === $b ? PublicFiles::MAIL_IMAGE_MAX_BYTES + 1 : PublicFiles::MAIL_IMAGE_MAX_BYTES);

        self::assertSame(
            [
                [ConfigurationFinding::DurabilityLabelNotPublic, ['product' => 'Geschützt', 'years' => '5']],
                [ConfigurationFinding::DurabilityLabelTooLarge, ['product' => 'Schwer', 'years' => '5', 'size' => '101']],
            ],
            $this->issues($files),
        );
        self::assertTrue(ConfigurationFinding::DurabilityLabelNotPublic->isWarning());
        self::assertFalse(ConfigurationFinding::DurabilityLabelTooLarge->isWarning());
    }

    /**
     * GA8: ein geschachteltes Label, das nie erscheint — ohne volles Label,
     * ohne gültige Dauer, aus einem nicht öffentlichen Ordner. Ein Hinweis.
     */
    public function testEinGeschachteltesLabelDasNieErscheint(): void
    {
        $this->product(1, 'Ohne Label', ['durabilityYears' => 5, 'durabilityNested' => str_repeat('n', 16)]);
        $this->product(2, 'Ohne Dauer', ['durabilityLabel' => str_repeat('r', 16), 'durabilityNested' => str_repeat('n', 16)]);
        $this->product(3, 'Geschützt', ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('r', 16), 'durabilityNested' => str_repeat('p', 16)]);
        $this->product(4, 'Richtig', ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('r', 16), 'durabilityNested' => str_repeat('n', 16)]);
        $this->product(5, 'Nur geschachtelt', ['durabilityNested' => str_repeat('n', 16)]);

        $files = $this->createMock(PublicFiles::class);
        $files->method('isPublic')->willReturnCallback(static fn (string $b): bool => str_repeat('p', 16) !== $b);
        $files->method('size')->willReturn(1000);

        $this->product(6, 'Label geschützt', ['durabilityYears' => 5, 'durabilityLabel' => str_repeat('p', 16), 'durabilityNested' => str_repeat('n', 16)]);

        $byProduct = [];

        foreach ($this->issues($files) as [$finding, $context]) {
            $byProduct[$context['product']][] = $finding;
        }

        // Je Produkt genau EIN Befund (Review GA8, 4): wo das volle Label
        // einen eigenen hat, deckt der den geschachtelten mit.
        self::assertSame([
            'Geschützt' => [ConfigurationFinding::DurabilityNestedUnused],
            'Label geschützt' => [ConfigurationFinding::DurabilityLabelNotPublic],
            'Nur geschachtelt' => [ConfigurationFinding::DurabilityNestedUnused],
            'Ohne Dauer' => [ConfigurationFinding::DurabilityYearsMissing],
            'Ohne Label' => [ConfigurationFinding::DurabilityLabelMissing],
        ], $byProduct);
        self::assertFalse(ConfigurationFinding::DurabilityNestedUnused->isWarning());
    }

    /**
     * @return list<array{ConfigurationFinding, array<string, string>}>
     */
    private function issues(PublicFiles|null $files = null): array
    {
        return array_map(static fn ($i): array => [$i->finding, $i->context], (new DurabilityGuaranteeIssueSource($this->connection, $files))->issues());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function product(int $id, string $title, array $values = []): void
    {
        $this->connection->insert('tl_consho_product', ['id' => $id, 'title' => $title, 'productType' => 1, 'published' => 1, ...$values]);
    }
}

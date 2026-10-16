<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Slug\Slug;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasScope;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class AliasFactoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Das Testschema muss mindestens so streng sein wie die echte
        // Datenbank — sonst wirkt eine Gefahr plausibel, die real unmöglich
        // ist (siehe contao/dca/tl_consho_translation.php und M-2).
        DcaSchema::create($this->connection, 'tl_consho_translation');

        // Eigenschaftsoptionen haben in der echten DCA keinen Unique-Key,
        // nur einen Index — die Eindeutigkeit je Gruppe ist ausdrücklich
        // AliasFactorys Job (contao/dca/tl_consho_property_option.php).
        DcaSchema::create($this->connection, 'tl_consho_property_option');
    }

    public function testTheSameAliasIsFreeInAnotherLanguage(): void
    {
        $this->translation('tl_consho_product', 'en', 'bitters');

        $alias = $this->factory()->forRecord(
            'tl_consho_translation',
            'bitters',
            'Bitters',
            2,
            new AliasScope('ptable', 'tl_consho_product', 'language', 'fr'),
        );

        self::assertSame('bitters', $alias);
    }

    public function testTheSameAliasIsTakenInTheSameLanguage(): void
    {
        $this->translation('tl_consho_product', 'en', 'bitters');

        // Leer eingegeben, damit der Zähl-Mechanismus des Slug-Generators
        // greift statt der direkten "existiert schon"-Ausnahme.
        $alias = $this->factory($this->countingSlug('bitters'))->forRecord(
            'tl_consho_translation',
            '',
            'Bitters',
            2,
            new AliasScope('ptable', 'tl_consho_product', 'language', 'en'),
        );

        self::assertSame('bitters-2', $alias);
    }

    public function testTheSameAliasIsFreeForAnotherParentTable(): void
    {
        $this->translation('tl_consho_product', 'en', 'bitters');

        $alias = $this->factory()->forRecord(
            'tl_consho_translation',
            'bitters',
            'Bitters',
            2,
            new AliasScope('ptable', 'tl_consho_manufacturer', 'language', 'en'),
        );

        self::assertSame('bitters', $alias);
    }

    public function testTheExistingSingleDimensionScopeStillWorks(): void
    {
        // Regressionstest: der heutige Eigenschaftsoptionen-Fall — eine
        // einzige Dimension, ohne zweite Spalte — muss unverändert
        // funktionieren.
        $this->connection->insert('tl_consho_property_option', ['pid' => 5, 'alias' => 'rot']);

        $free = $this->factory()->forRecord(
            'tl_consho_property_option',
            'rot',
            'Rot',
            99,
            new AliasScope('pid', 6),
        );

        self::assertSame('rot', $free);

        $this->expectException(\RuntimeException::class);

        $this->factory()->forRecord(
            'tl_consho_property_option',
            'rot',
            'Rot',
            99,
            new AliasScope('pid', 5),
        );
    }

    private function factory(?Slug $slug = null): AliasFactory
    {
        return new AliasFactory($slug ?? $this->createMock(Slug::class), $this->connection);
    }

    /**
     * Simuliert den Zähl-Mechanismus von Contao\CoreBundle\Slug\Slug::generate()
     * (dieselbe Schleife), damit die an ihn übergebene Duplikatprüfung wirklich
     * ausgeführt wird — ohne echten SlugGenerator und ContaoFramework aufzusetzen.
     */
    private function countingSlug(string $base): Slug
    {
        $slug = $this->createMock(Slug::class);
        $slug->method('generate')->willReturnCallback(
            static function (string $text, array $options, callable $duplicateCheck) use ($base): string {
                $candidate = $base;

                for ($count = 2; $duplicateCheck($candidate); ++$count) {
                    $candidate = $base.'-'.$count;
                }

                return $candidate;
            },
        );

        return $slug;
    }

    private function translation(string $ptable, string $language, string $alias): void
    {
        $this->connection->insert('tl_consho_translation', [
            'ptable' => $ptable,
            'language' => $language,
            'alias' => $alias,
        ]);
    }
}

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

        // The test schema must be at least as strict as the real
        // database — otherwise a danger looks plausible that is impossible
        // in reality (see contao/dca/tl_consho_translation.php and M-2).
        DcaSchema::create($this->connection, 'tl_consho_translation');

        // Property options have no unique key in the real DCA,
        // only an index — uniqueness per group is explicitly
        // AliasFactory's job (contao/dca/tl_consho_property_option.php).
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

        // Entered empty, so that the counting mechanism of the slug generator
        // applies instead of the direct "already exists" exception.
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
        // Regression test: today's property option case — a
        // single dimension, without a second column — must keep
        // working unchanged.
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
     * Simulates the counting mechanism of Contao\CoreBundle\Slug\Slug::generate()
     * (the same loop), so that the duplicate check passed to it really runs —
     * without setting up a real SlugGenerator and ContaoFramework.
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

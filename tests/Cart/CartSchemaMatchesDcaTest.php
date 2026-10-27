<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cart test schema is built from the DCA (DcaSchema), `order` included
 * and quoted. This test keeps it that way: it compares the columns of the
 * created tables with the DCA fields that have an SQL column, so a schema
 * that falls behind the DCA (a test schema must use the production columns;
 * most recently Block H with `discountCode`) fails here.
 */
class CartSchemaMatchesDcaTest extends TestCase
{
    use CartSchema;

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        yield 'tl_consho_cart' => ['tl_consho_cart'];
        yield 'tl_consho_cart_item' => ['tl_consho_cart_item'];
    }

    #[DataProvider('tables')]
    public function testDieSpaltenSindDieDerDca(string $table): void
    {
        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCartTables();

        $GLOBALS['TL_DCA'] ??= [];
        require __DIR__.'/../../contao/dca/'.$table.'.php';

        $dca = array_keys(array_filter($GLOBALS['TL_DCA'][$table]['fields'], static fn (array $field): bool => isset($field['sql'])));
        $schema = array_map(static fn (array $row): string => (string) $row['name'], $this->cartConnection->fetchAllAssociative('PRAGMA table_info('.$table.')'));

        sort($dca);
        sort($schema);

        self::assertSame($dca, $schema);
    }
}

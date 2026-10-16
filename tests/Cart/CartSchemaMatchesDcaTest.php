<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Das Warenkorb-Testschema ist handgeschrieben — wegen `order`, das DcaSchema
 * nicht anlegen kann. Damit es nicht wieder still hinter der DCA zurückbleibt
 * (Prüfungsnotiz, Fall 31; zuletzt Block H mit `discountCode`), vergleicht
 * dieser Test die Spalten mit den Feldern der DCA, die eine SQL-Spalte haben.
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

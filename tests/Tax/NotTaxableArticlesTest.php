<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tax\ContaoTaxRateRepository;
use Webwerkwien\ContaoConshoBundle\Tax\NotTaxableArticles;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Abnahme WG-7: „inkl. USt." stand unter einem nicht steuerbaren Gutschein.
 * Der Satz entfällt nur, wo ALLES Gezeigte nicht steuerbar ist.
 */
class NotTaxableArticlesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_product', 'tl_consho_tax_class'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_tax_class', ['id' => 1, 'title' => 'Waren']);
        $this->connection->insert('tl_consho_tax_class', ['id' => 7, 'title' => 'Gutschein', 'notTaxable' => 1]);

        // 13 Gutschein, 14 seine Variante ohne eigene Klasse, 4 ein Buch.
        $this->connection->insert('tl_consho_product', ['id' => 13, 'pid' => 0, 'taxClass' => 7]);
        $this->connection->insert('tl_consho_product', ['id' => 14, 'pid' => 13, 'taxClass' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 4, 'pid' => 0, 'taxClass' => 1]);
    }

    public function testNurGutscheineSindNichtSteuerbar(): void
    {
        self::assertTrue($this->articles()->all([13]));
        self::assertTrue($this->articles()->all([13, 14]), 'die Variante erbt die Klasse');
    }

    public function testEinGemischterKorbBehaeltDenSatz(): void
    {
        self::assertFalse($this->articles()->all([13, 4]));
        self::assertEquals([13 => true, 4 => false], $this->articles()->each([13, 4]));
    }

    public function testLeerOderUnbekanntIstNichtNichtSteuerbar(): void
    {
        self::assertFalse($this->articles()->all([]));
        self::assertFalse($this->articles()->all([13, 99]));
    }

    private function articles(): NotTaxableArticles
    {
        return new NotTaxableArticles($this->connection, new ContaoTaxRateRepository($this->connection));
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Document\NumberPrefixIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Spec C, offene Punkte (Abnahme C-12): Zwei Belegnummernkreise mit demselben
 * Präfix vergeben dieselbe Nummer zweimal — „RE-00005" im Testshop und in
 * seiner Kopie. Ein Hinweis, keine Warnung: zwei Unternehmen dürfen dasselbe
 * Präfix haben, nur weiß der Kern nicht, ob es zwei sind.
 */
final class NumberPrefixIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_number_range');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Testshop 2']);
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'order', 'prefix' => 'WEB-']);
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-']);
        $this->connection->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'cancellation', 'prefix' => 'ST-']);
        $this->connection->insert('tl_consho_number_range', ['pid' => 2, 'type' => 'order', 'prefix' => 'WEB-']);
    }

    public function testVerschiedenePraefixeSindInOrdnung(): void
    {
        $this->connection->insert('tl_consho_number_range', ['pid' => 2, 'type' => 'invoice', 'prefix' => 'RE2-']);

        self::assertSame([], $this->issues(), 'gleiche Bestellnummern-Präfixe sind gewollt: Bestellnummern gelten je Shop');
    }

    public function testZweiShopsMitDemselbenRechnungspraefix(): void
    {
        $this->connection->insert('tl_consho_number_range', ['pid' => 2, 'type' => 'invoice', 'prefix' => 'RE-']);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::NumberPrefixShared, $issues[0]->finding);
        self::assertSame('RE-', $issues[0]->context['prefix']);
        self::assertSame('Testshop (Rechnungsnummer), Testshop 2 (Rechnungsnummer)', $issues[0]->context['ranges']);
        self::assertFalse($issues[0]->finding->isWarning());
    }

    /** Auch im selben Shop: Rechnung RE-00001 und Storno RE-00001. */
    public function testZweiBelegartenEinesShopsMitDemselbenPraefix(): void
    {
        $this->connection->update('tl_consho_number_range', ['prefix' => 're-'], ['pid' => 1, 'type' => 'cancellation']);

        self::assertSame('Testshop (Rechnungsnummer), Testshop (Stornonummer)', $this->issues()[0]->context['ranges'] ?? null, 'Groß/klein und Leerraum zählen nicht');
    }

    public function testLeeresPraefixZaehltAuch(): void
    {
        $this->connection->update('tl_consho_number_range', ['prefix' => ''], ['pid' => 1, 'type' => 'invoice']);
        $this->connection->insert('tl_consho_number_range', ['pid' => 2, 'type' => 'invoice', 'prefix' => ' ']);

        self::assertSame('', $this->issues()[0]->context['prefix'] ?? null);
    }

    /**
     * @return list<\Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue>
     */
    private function issues(): array
    {
        $GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'] = ['invoice' => 'Rechnungsnummer', 'cancellation' => 'Stornonummer'];

        try {
            return (new NumberPrefixIssueSource($this->connection))->issues();
        } finally {
            unset($GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes']);
        }
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\WeightBracketListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * E-4, V2: „Bis Gewicht" nur an den Preiszeilen einer Gewichtsstaffel — an
 * einer Pauschale wäre es ein Feld, das nichts bewirkt.
 */
class WeightBracketListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shipping_method');
        DcaSchema::create($this->connection, 'tl_consho_shipping_price');
        $this->connection->insert('tl_consho_shipping_method', ['id' => 1, 'type' => 'weight_rate']);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 2, 'type' => 'flat_rate']);
        // Absichtlich über Kreuz: Die Zeile 1 gehört zur Pauschale 2, die Zeile 2
        // zur Staffel 1 — so fällt auf, wenn Zeilen- und Methoden-ID verwechselt werden.
        $this->connection->insert('tl_consho_shipping_price', ['id' => 1, 'pid' => 2]);
        $this->connection->insert('tl_consho_shipping_price', ['id' => 2, 'pid' => 1]);

        $GLOBALS['TL_DCA']['tl_consho_shipping_price'] = [
            'list' => ['sorting' => ['fields' => ['currency', 'id']], 'label' => ['fields' => ['currency', 'net'], 'format' => '%s %s netto']],
            'palettes' => ['default' => '{price_legend},maxWeight,currency,inputCountry,inputMode,amount'],
            'fields' => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDieListeEinerStaffelSortiertNachGewicht(): void
    {
        $this->listener('')->onLoad($this->dc(1));

        self::assertSame(['currency', 'maxWeight'], $GLOBALS['TL_DCA']['tl_consho_shipping_price']['list']['sorting']['fields']);
        self::assertStringContainsString('maxWeight', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['palettes']['default']);
    }

    public function testBeimBearbeitenZaehltDieMethodeDerZeile(): void
    {
        $this->listener('edit')->onLoad($this->dc(2));
        self::assertStringContainsString('maxWeight', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['palettes']['default'], 'Zeile 2 gehört zur Staffel');

        $this->listener('edit')->onLoad($this->dc(1));
        self::assertStringNotContainsString('maxWeight', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['palettes']['default'], 'Zeile 1 gehört zur Pauschale');
    }

    public function testBeiDerPauschaleKeinGewichtsfeld(): void
    {
        $this->listener('')->onLoad($this->dc(2));

        self::assertStringNotContainsString('maxWeight', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['palettes']['default']);
        self::assertSame(['currency', 'id'], $GLOBALS['TL_DCA']['tl_consho_shipping_price']['list']['sorting']['fields']);
    }

    /**
     * Fable-Review E, K2: MariaDB liefert decimal(…,4) als „2.0000" — die
     * Liste zeigt „bis 2 kg: 4,9", nicht die Nullen (Fall 30).
     */
    public function testDieZeileWirdFormatiert(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_LANG']['tl_consho_shipping_price'] = ['rowLabel' => '%s %s netto', 'bracketLabel' => '%s — bis %s kg: %s netto'];

        self::assertSame('EUR — bis 2 kg: 4,9 netto', $this->listener('')->label(['currency' => 'EUR', 'maxWeight' => '2.0000', 'net' => '4.9000']));
        self::assertSame('EUR 5 netto', $this->listener('')->label(['currency' => 'EUR', 'maxWeight' => '0.0000', 'net' => '5.0000']));

        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_LANG']);
    }

    private function listener(string $act): WeightBracketListener
    {
        $requests = new RequestStack();
        $requests->push(new Request('' === $act ? [] : ['act' => $act]));

        return new WeightBracketListener($this->connection, $requests);
    }

    private function dc(int $id): DataContainer
    {
        // Over __get, like Contao's own DataContainer — which has no __isset.
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

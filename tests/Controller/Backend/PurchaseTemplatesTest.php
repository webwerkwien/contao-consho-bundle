<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\PurchaseBackendController;

/**
 * Block G, G-7: die fünf Vorlagen von Einkauf und Einkaufslisten, gerendert
 * mit den ECHTEN Sprachdateien — Review G-7, B1: Der Knopf „Eingang buchen"
 * las `l.receive`, und das ist die Beschriftung der Operation, eine Liste.
 * Twig brach mit „Array to string conversion" ab; kein Test hatte die
 * Vorlage je gerendert (Regel 24: die Vorlage ist die Stelle).
 */
class PurchaseTemplatesTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    /**
     * @dataProvider sprachen
     */
    public function testAlleVorlagenRendernMitDenSprachdateien(string $language, string $receiveButton): void
    {
        [$list, $items] = $this->lang($language);
        $twig = $this->twig();
        $row = ['number' => 'EK-0001', 'createdAt' => 1_790_000_000];

        $purchase = $twig->render('@Contao/backend/consho_purchase.html.twig', ['messages' => '', 'action' => '/contao', 'lang' => $list, 'lines' => [
            ['article' => 1, 'title' => 'Wanderstock & Hülle', 'sku' => 'WS', 'supplier' => 'Bergsport', 'supplierSku' => 'L-1', 'packUnit' => 6, 'stock' => '3', 'ordered' => '0', 'warning' => '5', 'target' => '20', 'proposed' => '18.0000', 'pieces' => '18', 'packs' => '3'],
            ['article' => 2, 'title' => 'Rucksack', 'sku' => '', 'supplier' => '', 'supplierSku' => '', 'packUnit' => 1, 'stock' => '10', 'ordered' => '0', 'warning' => '2', 'target' => '0', 'proposed' => null, 'pieces' => '', 'packs' => ''],
        ]]);
        self::assertStringContainsString('name="packs[1]" value="3"', $purchase);
        self::assertStringContainsString('name="pieces[2]" value=""', $purchase);
        self::assertStringContainsString('Wanderstock &amp; Hülle', $purchase);
        self::assertStringNotContainsString('formtarget', $purchase, 'kein Formular, das stehen bleibt (S1)');

        $receive = $twig->render('@Contao/backend/consho_purchase_receive.html.twig', ['messages' => '', 'action' => '/contao', 'back' => '/contao', 'lang' => $list, 'itemStates' => $items, 'list' => $row, 'items' => [
            ['id' => 5, 'title' => 'Wanderstock', 'sku' => 'WS', 'supplier' => 'Bergsport', 'quantity' => '18', 'received' => '6', 'rest' => '12', 'state' => 'partial', 'done' => false],
            ['id' => 6, 'title' => 'Rucksack', 'sku' => '', 'supplier' => '', 'quantity' => '4', 'received' => '0', 'rest' => '4', 'state' => 'unavailable', 'done' => true],
        ]]);
        self::assertStringContainsString($receiveButton, $receive);
        self::assertStringContainsString((string) $items['unavailable'], $receive, 'Zustände der Positionen, nicht der Liste (L1)');

        $output = $twig->render('@Contao/backend/consho_purchase_output.html.twig', ['lang' => $list, 'list' => $row, 'back' => '/contao', 'url' => '/contao?do=consho_stock&id=1', 'saved' => true, 'then' => 'csv']);
        self::assertStringContainsString('EK-0001', $output);
        self::assertStringContainsString('format=csv', $output);

        $rows = PurchaseBackendController::table([['supplier' => 'Bergsport', 'supplierSku' => 'L-1', 'sku' => 'WS', 'title' => 'Wanderstock', 'quantity' => '18', 'packUnit' => 6]], $list);

        foreach (['consho_purchase_print', 'consho_purchase_clipboard'] as $page) {
            $html = $twig->render('@Contao/backend/'.$page.'.html.twig', ['lang' => $list, 'list' => $row, 'rows' => $rows, 'text' => "a\tb", 'lists' => '/contao']);
            self::assertStringContainsString('EK-0001', $html, $page);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sprachen(): iterable
    {
        yield 'de' => ['de', 'Eingang buchen'];
        yield 'en' => ['en', 'Book receipt'];
    }

    public function testCsvZellenWerdenNichtZuFormeln(): void
    {
        self::assertSame("'=SUMME(A1)", PurchaseBackendController::csvCell('=SUMME(A1)'));
        self::assertSame("'@x", PurchaseBackendController::csvCell('@x'));
        self::assertSame('Bergsport', PurchaseBackendController::csvCell('Bergsport'));
        self::assertSame('', PurchaseBackendController::csvCell(''));
    }

    /**
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function lang(string $language): array
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/tl_consho_purchase_list.php';
        require __DIR__.'/../../../contao/languages/'.$language.'/tl_consho_purchase_item.php';

        /** @var array<string, mixed> $list */
        $list = $GLOBALS['TL_LANG']['tl_consho_purchase_list'];
        /** @var array<string, string> $items */
        $items = $GLOBALS['TL_LANG']['tl_consho_purchase_item']['states'];

        return [$list, $items];
    }

    private function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');

        // Not strict: the templates fall back with |default; what has to fail
        // is a key that IS there with the wrong type (B1).
        $twig = new Environment($loader, ['strict_variables' => false]);
        $twig->addGlobal('contao', ['request_token' => 'rt']);

        return $twig;
    }
}

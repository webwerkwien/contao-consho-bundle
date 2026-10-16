<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\DbalMiniCartIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalMiniCartIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($this->connection, 'tl_consho_shop');
        // ptable/pid, wie ein echtes tl_content: ein Artikel-Element trägt pid
        // auf tl_article, ein Layout-Bereich-Element seit Contao 5.7 pid auf
        // tl_theme (ModuleWizard, contao/dca/tl_content.php).
        $this->connection->executeStatement(
            'CREATE TABLE tl_content (id INTEGER PRIMARY KEY AUTOINCREMENT, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT \'\', type TEXT NOT NULL DEFAULT \'\', invisible INTEGER NOT NULL DEFAULT 0)',
        );
    }

    public function testOhneZurueckZurSeiteGibtEsKeinenHinweis(): void
    {
        $this->connection->insert('tl_consho_shop', ['title' => 'Shop', 'afterAddToCart' => 'cart']);

        self::assertSame([], $this->source()->issues());
    }

    public function testZurueckZurSeiteOhneMiniWarenkorbGibtEinenHinweis(): void
    {
        // Sonst sieht das Hinzufügen für den Besucher wie nichts aus.
        $this->connection->insert('tl_consho_shop', ['title' => 'Shop', 'afterAddToCart' => 'page']);

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::MiniCartMissing, $issues[0]->finding);
        self::assertFalse($issues[0]->finding->isWarning(), 'ein Hinweis sperrt nie (11.2)');
    }

    public function testEinMiniWarenkorbInEinemLayoutBereichGenuegt(): void
    {
        // Genau der Fall, für den das Element gebaut ist: seit Contao 5.7
        // stehen Layout-Bereich-Elemente mit ptable='tl_theme' statt an
        // einem Artikel — eine Abfrage über Artikel würde ihn übersehen.
        $this->connection->insert('tl_consho_shop', ['title' => 'Shop', 'afterAddToCart' => 'page']);
        $this->connection->insert('tl_content', ['pid' => 1, 'ptable' => 'tl_theme', 'type' => 'consho_mini_cart', 'invisible' => 0]);

        self::assertSame([], $this->source()->issues());
    }

    public function testEinMiniWarenkorbInEinemArtikelGenuegtEbenso(): void
    {
        $this->connection->insert('tl_consho_shop', ['title' => 'Shop', 'afterAddToCart' => 'page']);
        $this->connection->insert('tl_content', ['pid' => 1, 'ptable' => 'tl_article', 'type' => 'consho_mini_cart', 'invisible' => 0]);

        self::assertSame([], $this->source()->issues());
    }

    public function testEinUnsichtbarerMiniWarenkorbZaehltNicht(): void
    {
        $this->connection->insert('tl_consho_shop', ['title' => 'Shop', 'afterAddToCart' => 'page']);
        $this->connection->insert('tl_content', ['pid' => 1, 'ptable' => 'tl_theme', 'type' => 'consho_mini_cart', 'invisible' => 1]);

        self::assertCount(1, $this->source()->issues());
    }

    public function testEinNeuerShopBringtDenHinweisZurueck(): void
    {
        // Der Fingerabdruck entsteht aus dem Befund UND seinem Kontext. Ohne
        // die Shop-Namen wäre er für jeden Fall derselbe — ein einziges
        // Quittieren machte den Hinweis für immer stumm, auch für einen Shop,
        // den es heute noch gar nicht gibt. ConfigurationIssue sagt das selbst.
        $this->connection->insert('tl_consho_shop', ['title' => 'Erster', 'afterAddToCart' => 'page']);

        $vorher = $this->source()->issues()[0]->fingerprint();

        $this->connection->insert('tl_consho_shop', ['title' => 'Zweiter', 'afterAddToCart' => 'page']);

        self::assertNotSame($vorher, $this->source()->issues()[0]->fingerprint());
    }

    public function testNurBetroffeneShopsStehenImKontext(): void
    {
        // Ein Shop, der direkt zum Warenkorb schickt, braucht keinen
        // Mini-Warenkorb und gehört deshalb nicht in die Meldung.
        $this->connection->insert('tl_consho_shop', ['title' => 'Mit Rücksprung', 'afterAddToCart' => 'page']);
        $this->connection->insert('tl_consho_shop', ['title' => 'Direkt zum Korb', 'afterAddToCart' => 'cart']);

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(['shops' => 'Mit Rücksprung'], $issues[0]->context);
    }

    /**
     * @dataProvider sprachen
     */
    public function testDerHinweisNenntDieBetroffenenShops(string $sprache): void
    {
        // Befund 8: Die Shop-Titel stehen längst im Kontext — dort für den
        // Fingerabdruck gebraucht —, der Satz nutzte sie nur nicht und begann
        // mit „Ein Shop". Bei mehreren Shops erfuhr der Betreiber nicht,
        // welcher gemeint ist. shippingPriceMissing macht es danebenan vor.
        $this->connection->insert('tl_consho_shop', ['title' => 'Wien', 'afterAddToCart' => 'page']);
        $this->connection->insert('tl_consho_shop', ['title' => 'Zürich', 'afterAddToCart' => 'page']);

        $issue = $this->source()->issues()[0];

        self::assertSame(['shops' => 'Wien, Zürich'], $issue->context);

        // Gemessen wird der Satz gegen den Kontext, den die Quelle wirklich
        // liefert: Jeder Platzhalter muss gefüllt werden können, und jeder
        // Kontextwert muss im Satz vorkommen. Ein Platzhalter ohne Wert bliebe
        // als „%shops%" auf der Startseite stehen, ein Wert ohne Platzhalter
        // ist genau der Befund hier.
        self::assertSame(
            array_keys($issue->context),
            $this->platzhalterIn($this->findungstext($sprache, $issue->finding->value)),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function sprachen(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    private function findungstext(string $sprache, string $code): string
    {
        // Wie in ConfigurationFindingLanguageTest isoliert geladen: die Datei
        // ersetzt den Zweig komplett, ein Rest der vorigen Sprache im Speicher
        // fiele nicht auf.
        unset($GLOBALS['TL_LANG']);

        require __DIR__.'/../../contao/languages/'.$sprache.'/default.php';

        $text = $GLOBALS['TL_LANG']['CONSHO']['findings'][$code] ?? '';
        unset($GLOBALS['TL_LANG']);

        self::assertIsString($text);

        return $text;
    }

    /**
     * @return list<string>
     */
    private function platzhalterIn(string $text): array
    {
        preg_match_all('/%(\w+)%/', $text, $treffer);

        return array_values(array_unique($treffer[1]));
    }

    private function source(): DbalMiniCartIssueSource
    {
        return new DbalMiniCartIssueSource($this->connection);
    }
}

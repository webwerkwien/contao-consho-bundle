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
        // ptable/pid, like a real tl_content: an article element carries pid
        // on tl_article, a layout-area element since Contao 5.7 pid on
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
        // Otherwise adding looks like nothing to the visitor.
        $this->connection->insert('tl_consho_shop', ['title' => 'Shop', 'afterAddToCart' => 'page']);

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::MiniCartMissing, $issues[0]->finding);
        self::assertFalse($issues[0]->finding->isWarning(), 'ein Hinweis sperrt nie (11.2)');
    }

    public function testEinMiniWarenkorbInEinemLayoutBereichGenuegt(): void
    {
        // Exactly the case the element is built for: since Contao 5.7
        // layout-area elements sit with ptable='tl_theme' instead of on
        // an article — a query over articles would miss it.
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
        // The fingerprint is built from the finding AND its context. Without
        // the shop names it would be the same for every case — a single
        // acknowledgement would silence the notice forever, even for a shop
        // that does not exist yet today. ConfigurationIssue says so itself.
        $this->connection->insert('tl_consho_shop', ['title' => 'Erster', 'afterAddToCart' => 'page']);

        $vorher = $this->source()->issues()[0]->fingerprint();

        $this->connection->insert('tl_consho_shop', ['title' => 'Zweiter', 'afterAddToCart' => 'page']);

        self::assertNotSame($vorher, $this->source()->issues()[0]->fingerprint());
    }

    public function testNurBetroffeneShopsStehenImKontext(): void
    {
        // A shop that sends straight to the cart needs no
        // mini cart and therefore does not belong in the message.
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
        // The shop titles have long been in the context — needed there for the
        // fingerprint — but the sentence did not use them and began
        // with "A shop". With several shops the operator did not learn
        // which one was meant. shippingPriceMissing next door shows how.
        $this->connection->insert('tl_consho_shop', ['title' => 'Wien', 'afterAddToCart' => 'page']);
        $this->connection->insert('tl_consho_shop', ['title' => 'Zürich', 'afterAddToCart' => 'page']);

        $issue = $this->source()->issues()[0];

        self::assertSame(['shops' => 'Wien, Zürich'], $issue->context);

        // The sentence is measured against the context the source really
        // supplies: every placeholder must be fillable, and every
        // context value must appear in the sentence. A placeholder without a value would
        // remain as "%shops%" on the start page, a value without a placeholder
        // is exactly the gap here.
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
        // Loaded in isolation as in ConfigurationFindingLanguageTest: the file
        // replaces the branch completely, a remnant of the previous language in memory
        // would not stand out.
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

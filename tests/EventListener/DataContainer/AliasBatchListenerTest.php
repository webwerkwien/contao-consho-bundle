<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Slug\Slug;
use Contao\DataContainer;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\AliasBatchListener;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

class AliasBatchListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['MSC']['aliasSelected'] = 'Aliase generieren';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testAddsTheButton(): void
    {
        $buttons = $this->listener(granted: true)->onManufacturer([], $this->dc());

        self::assertArrayHasKey('alias', $buttons);
        self::assertStringContainsString('Aliase generieren', $buttons['alias']);
    }

    public function testKeepsExistingButtons(): void
    {
        // The callback receives what other callbacks have added. Returning only
        // our own would remove Contao's "Edit" and "Delete" from the bar.
        $buttons = $this->listener(granted: true)->onManufacturer(['edit' => '<button>Edit</button>'], $this->dc());

        self::assertArrayHasKey('edit', $buttons);
        self::assertArrayHasKey('alias', $buttons);
    }

    public function testOffersNothingWithoutPermissionForTheField(): void
    {
        /*
         * A button that appears and then silently does nothing is worse than no
         * button — the editor cannot tell a permission from a bug.
         */
        $buttons = $this->listener(granted: false)->onManufacturer([], $this->dc());

        self::assertSame([], $buttons);
    }

    public function testDoesNothingWithoutTheSubmit(): void
    {
        // Merely opening the selection view must not rewrite every alias.
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('update');

        $listener = $this->listener(granted: true, connection: $connection);

        $listener->onManufacturer([], $this->dc());
    }

    public function testARegeneratedProductAliasIsRecordedBeforeItIsWritten(): void
    {
        /*
         * The button writes via UPDATE, past the save_callback — without this
         * the old product URL would be lost. The order matters: the recorder
         * builds the old URL from the database.
         */
        $log = [];

        $this->runBatch('tl_consho_product', $log);

        self::assertSame(['record', 'update'], $log);
    }

    public function testEinNeuGenerierterHerstellerAliasWirdVorDemSchreibenAufgezeichnet(): void
    {
        /*
         * The manufacturer alias is part of the URL of each of its products if
         * the template contains {manufacturer}. "Generate aliases" in particular
         * hits manufacturers from before B-1 — until then their products had a
         * URL without this segment.
         */
        $log = [];

        $this->runBatch('tl_consho_manufacturer', $log);

        self::assertSame(['record', 'update'], $log);
    }

    public function testEinNeuGenerierterProdukttypAliasWirdVorDemSchreibenAufgezeichnet(): void
    {
        $log = [];

        $this->runBatch('tl_consho_product_type', $log);

        self::assertSame(['record', 'update'], $log);
    }

    public function testEineEigenschaftsgruppeWirdNichtAlsProduktadresseAufgezeichnet(): void
    {
        // Filter aliases are not product URLs.
        $log = [];

        $this->runBatch('tl_consho_property_group', $log);

        self::assertSame(['update'], $log);
    }

    /**
     * Runs "Generate aliases" for record 5 ("Kräutersalz", stored alias
     * "kraeutersalz", regenerated "kraeutersalz-neu") and writes into $log what
     * reaches the database: the recorder asking for startpoints, the UPDATE.
     *
     * @param list<string> $log
     */
    private function runBatch(string $table, array &$log): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('update')->willReturnCallback(static function () use (&$log): int {
            $log[] = 'update';

            return 1;
        });

        $recorderConnection = $this->createMock(Connection::class);
        $recorderConnection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql) use (&$log): array {
            if (str_contains($sql, "type = 'root'")) {
                $log[] = 'record';
            }

            return [];
        });

        $slug = $this->createMock(Slug::class);
        $slug->method('generate')->willReturn('kraeutersalz-neu');

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        $session = new Session(new MockArraySessionStorage());
        $session->set('CURRENT', ['IDS' => [5]]);

        $request = Request::create('/contao?do=consho_product', 'POST', ['alias' => '', 'FORM_SUBMIT' => 'tl_select']);
        $request->setSession($session);

        $requests = new RequestStack();
        $requests->push($request);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('createInstance')->willReturn($this->createMock(Versions::class));

        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->with('table')->willReturn($table);
        $dc->method('getCurrentRecord')->willReturn(['id' => 5, 'title' => 'Kräutersalz', 'alias' => 'kraeutersalz']);

        $listener = new AliasBatchListener(
            new AliasFactory($slug, $connection),
            $connection,
            $security,
            $requests,
            $framework,
            new RedirectRecorder($recorderConnection, $this->createMock(ProductPageRepositoryInterface::class), new ShopLanguages($recorderConnection)),
        );

        try {
            $listener->onProduct([], $dc);
            self::fail('Nach dem Stapellauf folgt Post/Redirect/Get.');
        } catch (RedirectResponseException) {
        }
    }

    private function listener(bool $granted, Connection|null $connection = null): AliasBatchListener
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($granted);

        $requests = new RequestStack();
        $requests->push(new Request());

        return new AliasBatchListener(
            new AliasFactory(
                $this->createMock(Slug::class),
                $connection ?? $this->createMock(Connection::class),
            ),
            $connection ?? $this->createMock(Connection::class),
            $security,
            $requests,
            $this->createMock(ContaoFramework::class),
            new RedirectRecorder($this->createMock(Connection::class), $this->createMock(ProductPageRepositoryInterface::class), new ShopLanguages($this->createMock(Connection::class))),
        );
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->with('table')->willReturn('tl_consho_manufacturer');

        return $dc;
    }
}

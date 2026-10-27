<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FrozenVersionListener;

/**
 * Connect-2, CW-1 (E1): versions of orders and their parts are history —
 * restoring is refused on every path.
 */
final class FrozenVersionListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tables(): iterable
    {
        foreach (FrozenVersionListener::TABLES as $table) {
            yield $table => [$table];
        }
    }

    /**
     * contao-ai restores within a transaction: the exception rolls back the UPDATE.
     */
    #[DataProvider('tables')]
    public function testWiederherstellenWirdAufJedemWegVerweigert(string $table): void
    {
        try {
            $this->listener()->refuseRestore($table, 5, 2, []);
            self::fail('Das Wiederherstellen hätte verweigert werden müssen.');
        } catch (\Throwable $e) {
            // Exactly this class: contao-ai reports only it as a rejection, no subclass (core 250e844).
            self::assertSame(\InvalidArgumentException::class, $e::class);
            self::assertStringStartsWith($table.' 5:', $e->getMessage());
        }
    }

    /**
     * The backend restores without a transaction — hence the form is rejected already.
     */
    public function testDasFormularImBackendWirdVorDemSchreibenAbgewiesen(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['versionFrozen'] = 'Nicht wiederherstellbar.';
        $messages = $this->getMockBuilder(Adapter::class)->disableOriginalConstructor()->addMethods(['addError'])->getMock();
        $messages->expects(self::once())->method('addError')->with('Nicht wiederherstellbar.');

        $request = Request::create('https://shop.example/contao?do=consho_order&act=edit&id=5', 'POST', ['FORM_SUBMIT' => 'tl_version', 'version' => '2']);

        try {
            $this->listener($request, $messages)->refuseRestoreForm();
            self::fail('Das Formular hätte abgewiesen werden müssen.');
        } catch (RedirectResponseException $e) {
            // Back to the same form, as a GET.
            self::assertSame($request->getUri(), $e->getResponse()->headers->get('Location'));
        }
    }

    /**
     * Comparing (GET `versions`) and normal saving remain.
     *
     * @return iterable<string, array{Request|null}>
     */
    public static function otherRequests(): iterable
    {
        yield 'Vergleichen' => [Request::create('https://shop.example/contao?do=consho_order&act=edit&id=5&versions=1&from=1&to=2')];
        yield 'Speichern' => [Request::create('https://shop.example/contao?do=consho_order&act=edit&id=5', 'POST', ['FORM_SUBMIT' => 'tl_consho_order'])];
        yield 'Konsole' => [null];
    }

    #[DataProvider('otherRequests')]
    public function testAllesAndereBleibt(Request|null $request): void
    {
        $this->listener($request)->refuseRestoreForm();

        $this->addToAssertionCount(1);
    }

    /**
     * The list and the callbacks do not drift apart: every table has both
     * guards, and there is no guard for a table outside the list.
     */
    public function testJedeTabelleHatBeideWaechter(): void
    {
        foreach (['refuseRestoreForm' => 'config.onload', 'refuseRestore' => 'config.onrestore_version'] as $method => $target) {
            $tables = [];

            foreach ((new \ReflectionMethod(FrozenVersionListener::class, $method))->getAttributes(AsCallback::class) as $attribute) {
                $arguments = $attribute->getArguments();
                self::assertSame($target, $arguments['target']);
                $tables[] = $arguments['table'];
            }

            self::assertSame(FrozenVersionListener::TABLES, $tables, $method);
        }
    }

    /**
     * Only tables that keep versions at all — otherwise there would be nothing
     * to refuse, and a newly versioned order table would show up here if missing.
     */
    public function testDieListeSindGenauDieVersioniertenBestelltabellen(): void
    {
        $versioned = [];

        foreach (glob(__DIR__.'/../../../contao/dca/tl_consho_*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, "'enableVersioning' => true")) {
                $versioned[] = basename($file, '.php');
            }
        }

        foreach (FrozenVersionListener::TABLES as $table) {
            self::assertContains($table, $versioned, $table.' führt keine Versionen');
        }
    }

    /**
     * @param Adapter<Message>|null $messages
     */
    private function listener(Request|null $request = null, Adapter|null $messages = null): FrozenVersionListener
    {
        $requests = new RequestStack();

        if (null !== $request) {
            $requests->push($request);
        }

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($messages ?? $this->createStub(Adapter::class));

        return new FrozenVersionListener($requests, $framework);
    }
}

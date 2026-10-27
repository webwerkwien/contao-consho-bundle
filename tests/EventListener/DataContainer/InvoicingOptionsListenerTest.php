<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\InvoicingOptionsListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing\FakeInvoiceProvider;

class InvoicingOptionsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_shop']['invoicingOptions'] = ['consho' => 'Consho', 'off' => 'Aus', 'missing' => '%s (nicht installiert)'];
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testConshoZuerstDannDieModuleUndAusZuletzt(): void
    {
        // The order of the switch: Consho | modules | Aus.
        self::assertSame(
            ['consho' => 'Consho', 'fake_books' => 'Fake Books', 'off' => 'Aus'],
            $this->listener(['fake_books' => new FakeInvoiceProvider()])->options(),
        );
    }

    public function testOhneModulGibtEsNurConshoUndAus(): void
    {
        self::assertSame(['consho' => 'Consho', 'off' => 'Aus'], $this->listener([])->options());
    }

    public function testEinGewaehltesUndDannEntferntesModulBleibtWaehlbar(): void
    {
        // Missing from the options, Contao would drop the stored value on the
        // next save and the shop would silently fall back to Consho.
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'invoicing' => 'old_books']);

        self::assertSame(
            ['consho' => 'Consho', 'old_books' => 'old_books (nicht installiert)', 'off' => 'Aus'],
            $this->listener([])->options($this->dc(['id' => 3, 'invoicing' => 'old_books'])),
        );
    }

    public function testEinGespeichertesConshoOderAusFuegtNichtsHinzu(): void
    {
        foreach (['consho', 'off'] as $i => $stored) {
            $this->connection->insert('tl_consho_shop', ['id' => $i + 1, 'invoicing' => $stored]);

            self::assertSame(['consho' => 'Consho', 'off' => 'Aus'], $this->listener([])->options($this->dc(['id' => $i + 1, 'invoicing' => $stored])), $stored);
        }
    }

    public function testEinNeuerWertImDatensatzGiltNichtAlsGespeichert(): void
    {
        // A write outside the back end hands in the record with the new values
        // already in it; a mistyped module name must not become an option.
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'invoicing' => 'consho']);

        self::assertSame(
            ['consho' => 'Consho', 'off' => 'Aus'],
            $this->listener([])->options($this->dc(['id' => 3, 'invoicing' => 'bogus'])),
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    private function dc(array $record): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn($record);

        return $dc;
    }

    /**
     * @param array<string, FakeInvoiceProvider> $providers
     */
    private function listener(array $providers): InvoicingOptionsListener
    {
        return new InvoicingOptionsListener(new InvoiceProviders(new ServiceLocator(
            array_map(static fn (FakeInvoiceProvider $p): \Closure => static fn (): FakeInvoiceProvider => $p, $providers),
        )), $this->connection);
    }
}

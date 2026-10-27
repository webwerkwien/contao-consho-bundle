<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\InvoicingOptionsListener;
use Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing\FakeInvoiceProvider;

class InvoicingOptionsListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_shop']['invoicingOptions'] = ['consho' => 'Consho', 'off' => 'Aus', 'missing' => '%s (nicht installiert)'];
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
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['invoicing' => 'old_books']);

        self::assertSame(
            ['consho' => 'Consho', 'old_books' => 'old_books (nicht installiert)', 'off' => 'Aus'],
            $this->listener([])->options($dc),
        );
    }

    public function testEinGespeichertesConshoOderAusFuegtNichtsHinzu(): void
    {
        foreach (['consho', 'off'] as $stored) {
            $dc = $this->createStub(DataContainer::class);
            $dc->method('getCurrentRecord')->willReturn(['invoicing' => $stored]);

            self::assertSame(['consho' => 'Consho', 'off' => 'Aus'], $this->listener([])->options($dc), $stored);
        }
    }

    /**
     * @param array<string, FakeInvoiceProvider> $providers
     */
    private function listener(array $providers): InvoicingOptionsListener
    {
        return new InvoicingOptionsListener(new InvoiceProviders(new ServiceLocator(
            array_map(static fn (FakeInvoiceProvider $p): \Closure => static fn (): FakeInvoiceProvider => $p, $providers),
        )));
    }
}

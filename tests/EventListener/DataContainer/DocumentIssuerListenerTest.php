<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DocumentIssuerListener;
use Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing\FakeInvoiceProvider;

/**
 * Block R (R16, R18): the document list says who issued a document and
 * offers PDF and HTML only where there is one.
 */
class DocumentIssuerListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_document'] = [
            'notIssued' => 'Rechnungsdaten (nicht ausgestellt)',
            'waitingFor' => 'wartet auf %s',
            'handOverFailed' => 'Übergabe an %s gescheitert',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDieBeschriftungSagtWerAusgestelltHat(): void
    {
        $listener = $this->listener();

        self::assertSame('RE-1 — Rechnung', $listener->label(['documentNumber' => 'RE-1', 'issuer' => 'consho'], 'RE-1 — Rechnung'));
        self::assertSame('<span class="tl_gray">Rechnungsdaten (nicht ausgestellt)</span> — Rechnung', $listener->label(['documentNumber' => null, 'issuer' => 'off'], ' — Rechnung'));
        // The module's label, not its name.
        self::assertSame('<span class="tl_gray">wartet auf Fake Books</span> — Rechnung', $listener->label(['documentNumber' => null, 'issuer' => 'fake_books', 'externalState' => 'pending'], ' — Rechnung'));
        self::assertSame('<span class="tl_gray">Übergabe an removed_books gescheitert</span> — Rechnung', $listener->label(['documentNumber' => null, 'issuer' => 'removed_books', 'externalState' => 'failed'], ' — Rechnung'));
        self::assertSame('FB-1 — Rechnung', $listener->label(['documentNumber' => 'FB-1', 'issuer' => 'fake_books', 'externalState' => 'issued'], 'FB-1 — Rechnung'));
    }

    public function testPdfUndHtmlNurWoEsSieGibt(): void
    {
        $cases = [
            'consho' => [['issuer' => 'consho'], true, true],
            'old row' => [['issuer' => ''], true, true],
            'off' => [['issuer' => 'off'], false, false],
            'module, waiting' => [['issuer' => 'fake_books', 'externalState' => 'pending'], false, false],
            'module, issued' => [['issuer' => 'fake_books', 'externalState' => 'issued'], true, false],
        ];

        foreach ($cases as $case => [$row, $pdf, $html]) {
            $pdfButton = $this->operation('invoicePdf', $row);
            $htmlButton = $this->operation('invoiceHtml', $row);

            $this->listener()->pdfButton($pdfButton);
            $this->listener()->htmlButton($htmlButton);

            self::assertSame($pdf, '' !== $pdfButton->getHtml(), $case.': PDF');
            self::assertSame($html, '' !== $htmlButton->getHtml(), $case.': HTML');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function operation(string $name, array $row): DataContainerOperation
    {
        return new DataContainerOperation($name, ['href' => 'key='.$name, 'icon' => 'show.svg', 'label' => [$name, '%s']], ['id' => 1] + $row, $this->createStub(DataContainer::class));
    }

    private function listener(): DocumentIssuerListener
    {
        $provider = new FakeInvoiceProvider();

        return new DocumentIssuerListener(new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])));
    }
}

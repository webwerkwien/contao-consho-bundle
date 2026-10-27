<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Pdf;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\DompdfRenderer;

class DompdfRendererTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/consho-dompdf-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->cacheDir);
    }

    public function testAusHtmlWirdEinPdf(): void
    {
        $pdf = (new DompdfRenderer($this->cacheDir))->render('<p>Rechnung RE-00001</p>');

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * The font cache lies under var/, not in vendor/ — on many hostings nobody
     * may write there.
     */
    public function testDerSchriftenCacheWirdAngelegt(): void
    {
        (new DompdfRenderer($this->cacheDir))->render('<p>x</p>');

        self::assertDirectoryExists($this->cacheDir);
    }

    /**
     * PDF/A is switched on: dompdf then writes an OutputIntent — and a template
     * that names Helvetica does NOT abort, because the default font is
     * DejaVu Sans and that one can be embedded.
     */
    public function testPdfAIstAnUndEineKernschriftBrichtNichtAb(): void
    {
        $pdf = (new DompdfRenderer($this->cacheDir))->render(
            '<p style="font-family: sans-serif">x</p><p style="font-family: serif; font-weight: bold">x</p>'
            .'<p style="font-family: Courier; font-style: italic">x</p><p style="font-family: Helvetica">x</p>',
        );

        self::assertStringContainsString('/OutputIntents', $pdf);
        self::assertStringContainsString('DejaVu', $pdf);
        self::assertStringNotContainsString('Helvetica', $pdf);
    }

    /**
     * An image as a data: URL arrives — that is how the invoice passes in its
     * logo.
     */
    public function testEinBildAlsDataAdresseWirdEingebettet(): void
    {
        $pdf = (new DompdfRenderer($this->cacheDir))->render('<img src="data:image/png;base64,'.self::PNG.'" alt="">');

        self::assertStringContainsString('/Subtype /Image', $pdf);
    }

    /**
     * 🔴 A foreign URL is NOT loaded. Otherwise every invoice would be a call
     * to a foreign server, with time and sender — and a file URL would read
     * from the project whatever the template names.
     *
     * Both images are missing from the PDF; the page is created anyway.
     */
    public function testFremdeUndLokaleAdressenWerdenNichtGeladen(): void
    {
        // INSIDE the chroot, so only the protocol list can stop it.
        mkdir($this->cacheDir, 0o775, true);
        $file = $this->cacheDir.'/logo.png';
        file_put_contents($file, base64_decode(self::PNG, true));

        try {
            $pdf = (new DompdfRenderer($this->cacheDir))->render(
                '<img src="https://example.com/logo.png" alt=""><img src="file://'.$file.'" alt=""><p>x</p>',
            );
        } finally {
            @unlink($file);
        }

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    /**
     * 🔴 The font table is NOT rewritten for every PDF. Two simultaneous
     * invoices could otherwise read a half-written file and abort in PDF/A
     * mode.
     *
     * Shown via the modification time: reset after the first run, unchanged
     * after the second.
     */
    public function testDieSchriftentabelleWirdNurGeschriebenWennSieAbweicht(): void
    {
        $renderer = new DompdfRenderer($this->cacheDir);
        $renderer->render('<p>x</p>');

        $map = $this->cacheDir.'/installed-fonts.json';
        touch($map, 1_000_000_000);
        clearstatcache();

        $renderer->render('<p style="font-family: serif">y</p>');
        clearstatcache();

        self::assertSame(1_000_000_000, filemtime($map));
        self::assertStringContainsString('DejaVuSerif', (string) file_get_contents($map));
    }

    /**
     * 🔴 Measured, not assumed: a CMYK JPEG makes dompdf abort in PDF/A mode
     * with an exception ("CMYK images are not supported when generating a
     * document in PDF/A mode"). Logos from prepress are often CMYK — that is
     * why InvoiceLogo rejects them, and the shop form does not accept them in
     * the first place. If this test ever fails because dompdf can do CMYK,
     * the defence may go.
     */
    public function testEinCmykBildBrichtPdfAAb(): void
    {
        $cmyk = base64_encode((string) file_get_contents(__DIR__.'/../Fixtures/logo-cmyk.jpg'));

        $this->expectExceptionMessage('CMYK images are not supported');

        (new DompdfRenderer($this->cacheDir))->render('<img src="data:image/jpeg;base64,'.$cmyk.'" alt="">');
    }

    // 1×1 px, opaque.
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}

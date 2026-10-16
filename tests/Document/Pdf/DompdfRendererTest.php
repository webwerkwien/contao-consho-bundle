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
     * Der Schriften-Cache liegt unter var/, nicht in vendor/ — dort darf auf
     * vielen Hostings niemand schreiben.
     */
    public function testDerSchriftenCacheWirdAngelegt(): void
    {
        (new DompdfRenderer($this->cacheDir))->render('<p>x</p>');

        self::assertDirectoryExists($this->cacheDir);
    }

    /**
     * PDF/A ist eingeschaltet: dompdf schreibt dann einen OutputIntent — und
     * eine Vorlage, die Helvetica nennt, bricht NICHT ab, weil die
     * Standardschrift DejaVu Sans ist und die einbettbar.
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
     * Ein Bild als data:-Adresse kommt an — so reicht die Rechnung ihr Logo
     * herein.
     */
    public function testEinBildAlsDataAdresseWirdEingebettet(): void
    {
        $pdf = (new DompdfRenderer($this->cacheDir))->render('<img src="data:image/png;base64,'.self::PNG.'" alt="">');

        self::assertStringContainsString('/Subtype /Image', $pdf);
    }

    /**
     * 🔴 Eine fremde Adresse wird NICHT geladen. Jede Rechnung wäre sonst ein
     * Aufruf bei einem fremden Server, samt Uhrzeit und Absender — und eine
     * Datei-Adresse läse aus dem Projekt, was immer die Vorlage nennt.
     *
     * Beide Bilder fehlen im PDF; die Seite entsteht trotzdem.
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
     * 🔴 Fable-Review 2026-09-24: Die Schriftentabelle wird NICHT bei jedem
     * PDF neu geschrieben. Zwei gleichzeitige Rechnungen konnten sonst eine
     * halb geschriebene Datei lesen und im PDF/A-Modus abbrechen.
     *
     * Belegt über die Änderungszeit: zurückgesetzt nach dem ersten Lauf,
     * unverändert nach dem zweiten.
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
     * 🔴 Gemessen, nicht angenommen: Ein CMYK-JPEG lässt dompdf im
     * PDF/A-Modus mit einer Exception abbrechen („CMYK images are not
     * supported when generating a document in PDF/A mode"). Logos aus der
     * Druckvorstufe sind oft CMYK — deshalb weist InvoiceLogo sie ab, und
     * die Shop-Maske nimmt sie gar nicht erst an. Schlägt dieser Test einmal
     * fehl, weil dompdf CMYK kann, darf die Abwehr fallen.
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

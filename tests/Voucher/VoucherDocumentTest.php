<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\PdfRendererInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherDocument;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;

/**
 * WG4, WG14: Der Gutschein, wie der Käufer ihn liest — in der Mail, auf der
 * Bestellseite und als PDF. Gerendert mit der echten Vorlage und den echten
 * Sprachdateien: ein fehlender Schlüssel stünde roh im Gutschein.
 */
class VoucherDocumentTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    /** @var list<string> */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_voucher', 'tl_consho_voucher_booking', 'tl_consho_shop'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 3, 'title' => 'Testshop', 'invoiceCompany' => 'Muster', 'invoiceLegalForm' => 'GmbH']);
        $this->connection->insert('tl_consho_shop', ['id' => 4, 'title' => 'Zweiter Shop']);

        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testCodeInGruppenWertUndRestguthaben(): void
    {
        $ledger = new VoucherLedger($this->connection);
        $id = $ledger->issue(3, 'EUR', '50', '50', null, 'purchase', self::NOW, 77, 10);
        $ledger->book($id, '-12.5', \Webwerkwien\ContaoConshoBundle\Voucher\VoucherBookingType::Redeem, self::NOW + 1);

        [$voucher] = $this->document()->view([$id], 'de');

        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $voucher['code']);
        self::assertSame(['50,00 €', '37,50 €', '', 'Muster GmbH', false], array_map(
            static fn (mixed $v): mixed => \is_string($v) ? str_replace("\u{a0}", ' ', $v) : $v,
            [$voucher['value'], $voucher['balance'], $voucher['validUntil'], $voucher['issuer'], $voucher['blocked']],
        ));
    }

    public function testAusstellerOhneRechnungsabsenderIstDerShop(): void
    {
        $id = (new VoucherLedger($this->connection))->issue(4, 'EUR', '25', '25', null, 'manual', self::NOW);

        self::assertSame('Zweiter Shop', $this->document()->view([$id], 'de')[0]['issuer']);
    }

    /**
     * Contao speichert Eingaben kodiert („Kräuter &amp; Co"); das PDF ist
     * Twig und kodiert selbst — sonst stünde „&amp;" auf dem Gutschein
     * (Spec C, offene Punkte: kodierte Betreibertexte außerhalb der Rechnung).
     */
    public function testAusstellerWirdDekodiert(): void
    {
        $this->connection->update('tl_consho_shop', ['invoiceCompany' => 'Kräuter &amp; Co', 'invoiceLegalForm' => 'KG'], ['id' => 3]);
        $this->connection->update('tl_consho_shop', ['title' => 'Shop &#40;DE&#41;'], ['id' => 4]);
        $ledger = new VoucherLedger($this->connection);

        self::assertSame('Kräuter & Co KG', $this->document()->view([$ledger->issue(3, 'EUR', '25', '25', null, 'manual', self::NOW)], 'de')[0]['issuer']);
        self::assertSame('Shop (DE)', $this->document()->view([$ledger->issue(4, 'EUR', '25', '25', null, 'manual', self::NOW)], 'de')[0]['issuer']);
    }

    /**
     * Die Bestellseite zeigt die gekauften Gutscheine der Bestellung — nicht
     * die, mit denen sie bezahlt wurde, und keine von Hand ausgestellten.
     */
    public function testDieBestellungZeigtIhreGekauftenGutscheine(): void
    {
        $ledger = new VoucherLedger($this->connection);
        $bought = $ledger->issue(3, 'EUR', '50', '50', null, 'purchase', self::NOW, 77, 10);
        $ledger->issue(3, 'EUR', '50', '50', null, 'purchase', self::NOW, 78, 11);
        $ledger->issue(3, 'EUR', '10', '0', null, 'manual', self::NOW, 77);

        self::assertSame([$bought], array_column($this->document()->ofOrder(77, 'de'), 'id'));
    }

    public function testJederGutscheinWirdEinPdfMitSeinemCode(): void
    {
        $ledger = new VoucherLedger($this->connection);
        $ids = [
            $ledger->issue(3, 'EUR', '50', '50', (new \DateTimeImmutable('2029-12-31 00:00:00'))->getTimestamp(), 'purchase', self::NOW, 77, 10),
            $ledger->issue(3, 'EUR', '25', '25', null, 'purchase', self::NOW, 77, 10),
        ];

        $pdfs = $this->document()->pdfs($ids, 'de');

        self::assertCount(2, $pdfs);
        self::assertMatchesRegularExpression('/^Gutschein-[A-Z2-9]{4}\.pdf$/', $pdfs[0]['fileName']);

        $views = $this->document()->view($ids, 'de');
        $first = str_replace("\u{a0}", ' ', $this->rendered[0]);
        self::assertStringContainsString($views[0]['code'], $first);
        self::assertStringContainsString('50,00 €', $first);
        self::assertStringContainsString('Gültig bis 31.12.2029', $first);
        self::assertStringContainsString('Unbefristet gültig', $this->rendered[1]);
        self::assertStringNotContainsString('CONSHO.', $first, 'jeder Schlüssel übersetzt');
    }

    public function testDasPdfEntstehtMitDemEchtenDompdf(): void
    {
        $id = (new VoucherLedger($this->connection))->issue(3, 'EUR', '50', '50', null, 'purchase', self::NOW);
        $cache = sys_get_temp_dir().'/consho-voucher-'.bin2hex(random_bytes(4));

        try {
            $pdfs = $this->document(new \Webwerkwien\ContaoConshoBundle\Document\Pdf\DompdfRenderer($cache))->pdfs([$id], 'de');
        } finally {
            foreach (glob($cache.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($cache);
        }

        self::assertStringStartsWith('%PDF-', $pdfs[0]['pdf']);
    }

    /**
     * Abnahme WG-7: im englischen Shop hieß der Anhang „Gutschein-…".
     */
    public function testDerDateinameFolgtDerSprache(): void
    {
        $id = (new VoucherLedger($this->connection))->issue(3, 'EUR', '25', '25', null, 'purchase', self::NOW, 77, 10);
        $saved = $GLOBALS['TL_LANG'];

        try {
            require __DIR__.'/../../contao/languages/en/default.php';
            $pdfs = $this->document()->pdfs([$id], 'en');
        } finally {
            $GLOBALS['TL_LANG'] = $saved;
        }

        self::assertMatchesRegularExpression('/^Gift-voucher-[A-Z2-9]{4}\.pdf$/', $pdfs[0]['fileName']);
    }

    private function document(PdfRendererInterface|null $renderer = null): VoucherDocument
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], string|null $domain = null, string|null $locale = null): string
            {
                $value = $GLOBALS['TL_LANG'];

                foreach (explode('.', $id) as $key) {
                    if (!\is_array($value) || !isset($value[$key])) {
                        return $id;
                    }

                    $value = $value[$key];
                }

                return !\is_string($value) ? $id : ([] === $parameters ? $value : vsprintf($value, array_values($parameters)));
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        $pdf = $this->createStub(PdfRendererInterface::class);
        $pdf->method('render')->willReturnCallback(function (string $html): string {
            $this->rendered[] = $html;

            return '%PDF-'.\count($this->rendered);
        });

        return new VoucherDocument($this->connection, $twig, $translator, $renderer ?? $pdf, new MoneyFormatter());
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\CoreBundle\EventListener\VirtualFieldsMappingListener;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\IbanListener;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;

class PaymentMethodDcaTest extends TestCase
{
    private const BANK_FIELDS = ['accountHolder', 'iban', 'bic', 'bankName', 'giroCode'];

    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_payment_method.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testDieBankverbindungStehtNurInDerPaletteDerVorkasse(): void
    {
        $palettes = $GLOBALS['TL_DCA']['tl_consho_payment_method']['palettes'];

        foreach (self::BANK_FIELDS as $field) {
            self::assertStringContainsString($field, $palettes['prepayment'], $field);
            self::assertStringNotContainsString($field, $palettes['default'], $field);
        }
    }

    public function testDieBankverbindungLandetInJsonDataNichtInEigenenSpalten(): void
    {
        // In der Praxis trägt DataContainerCallbackListener (#[AsHook('loadDataContainer',
        // priority: -16)]) den save_callback aus IbanListener bereits ein, bevor
        // VirtualFieldsMappingListener (priority: -4096) läuft. Das wird hier nachgestellt,
        // weil dieser Test die DCA-Datei isoliert lädt, ohne die Hooks zu durchlaufen.
        $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']['iban']['save_callback'] = [[IbanListener::class, '__invoke']];

        // C3: Moduleinstellungen als virtuelle Felder — nach einer Deinstallation
        // blieben eigene Spalten stehen (Isotope, Merconis).
        (new VirtualFieldsMappingListener())('tl_consho_payment_method');

        $fields = $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields'];

        foreach (self::BANK_FIELDS as $field) {
            self::assertArrayNotHasKey('sql', $fields[$field], $field);
            self::assertSame('jsonData', $fields[$field]['targetColumn'] ?? null, $field);
        }

        self::assertTrue($fields['jsonData']['virtualTarget']);
    }

    /**
     * Der Giro-Code trägt höchstens 70 Zeichen Empfängername und darf ihn
     * nicht kürzen (EPC069-12 v3.1, 2.2) — also lehnt die Maske Längeres ab.
     * Der Schalter sitzt im Abschnitt Bankverbindung, nicht an der Vorkasse.
     */
    public function testDerKontoinhaberPasstInDenGiroCodeUndDerSchalterSitztBeiDerBank(): void
    {
        $dca = $GLOBALS['TL_DCA']['tl_consho_payment_method'];

        self::assertSame(70, $dca['fields']['accountHolder']['eval']['maxlength']);
        self::assertStringContainsString('{bank_legend},accountHolder,iban,bic,bankName,giroCode;', $dca['palettes']['prepayment']);
        self::assertSame('checkbox', $dca['fields']['giroCode']['inputType']);
        self::assertArrayNotHasKey('sql', $dca['fields']['giroCode']);
    }

    public function testDieIbanNenntIhreSpalteSelbst(): void
    {
        // Sie bekommt per #[AsCallback] einen save_callback — und ein Feld mit
        // save_callback ordnet Contao nicht mehr automatisch jsonData zu.
        self::assertSame('jsonData', $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']['iban']['targetColumn']);
    }

    public function testKeinVirtuellesFeldTraegtUniqueOderFallback(): void
    {
        // DcaExtractor verbietet beides auf virtuellen Feldern.
        foreach (self::BANK_FIELDS as $field) {
            $eval = $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields'][$field]['eval'] ?? [];

            self::assertArrayNotHasKey('unique', $eval, $field);
            self::assertArrayNotHasKey('fallback', $eval, $field);
        }
    }

    /**
     * Spec E, offene Punkte (Abnahme E-7): Contao setzt beim Anlegen die
     * Vorgabe JEDES Feldes, auch eines virtuellen aus einer fremden Palette —
     * die Nachnahme trug `dueDays: 14` in jsonData. Vorgaben virtueller Felder
     * gehören in den Handler; das Zahlungsziel zeigt seine als Platzhalter.
     */
    public function testKeinVirtuellesFeldHatEineVorgabe(): void
    {
        foreach ($GLOBALS['TL_DCA']['tl_consho_payment_method']['fields'] as $name => $field) {
            if (!isset($field['sql'])) {
                self::assertArrayNotHasKey('default', $field, $name);
            }
        }

        $dueDays = $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']['dueDays']['eval'];

        self::assertSame((string) InvoicePaymentHandler::DEFAULT_DUE_DAYS, $dueDays['placeholder'] ?? null);
        self::assertArrayNotHasKey('mandatory', $dueDays, 'leer heißt die Vorgabe des Handlers');
    }

    public function testKeinFreiesGebuehrenfeld(): void
    {
        // C5: § 56 Abs 3 ZaDiG 2018 — die Maske lädt nicht zum Aufschlag ein.
        // Seit E-1 gibt es die STEUERWAHL für eine Gebühr (Z3), aber weiter
        // kein Betragsfeld an jeder Zahlungsart; einen Betrag bringt nur die
        // Nachnahme mit (Z4, E-3) — und nur in ihrer Palette.
        $allowed = ['feeTaxMode', 'feeTaxClass', 'fee', 'feeCurrency'];

        foreach ($GLOBALS['TL_DCA']['tl_consho_payment_method']['palettes'] as $type => $palette) {
            if ('__selector__' === $type || 'cod' === $type) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression('/[,;]fee(Currency)?[,;]|[,;]fee(Currency)?$/', (string) $palette, $type.' hat ein Gebührenfeld');
        }

        self::assertStringContainsString('fee,feeCurrency', $GLOBALS['TL_DCA']['tl_consho_payment_method']['palettes']['cod']);

        foreach (array_diff(array_keys($GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']), $allowed) as $field) {
            self::assertStringNotContainsStringIgnoringCase('fee', (string) $field);
            self::assertStringNotContainsStringIgnoringCase('surcharge', (string) $field);
            self::assertStringNotContainsStringIgnoringCase('charge', (string) $field);
            self::assertStringNotContainsStringIgnoringCase('gebuehr', (string) $field);
            self::assertStringNotContainsStringIgnoringCase('aufschlag', (string) $field);
        }
    }

    public function testReservierteSchluesselSindKeineModulfelder(): void
    {
        // `instructions` und `net` sind reservierte Schlüssel im Config-Array
        // (PaymentHandlerInterface) — nie ein virtuelles Feld ohne `sql`.
        $fields = $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields'];

        self::assertArrayHasKey('sql', $fields['instructions']);

        self::assertTrue(
            !isset($fields['net']) || \array_key_exists('sql', $fields['net']),
            'net',
        );
    }

    /**
     * Michael, 2026-10-14: „ja geht in Ordnung" — das Geheimnis des
     * Test-Anbieters erscheint nicht in Lesewegen. `hideInput` blendet es in
     * Contaos Detailansicht und im Versionsvergleich aus, und contao-ai core
     * ab v1.8.1 maskiert es nach derselben Regel in allen Lesebefehlen.
     */
    public function testDasTestgeheimnisZeigtSichInKeinemLeseweg(): void
    {
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']['testSecret']['eval']['hideInput'] ?? false);
    }

    public function testLoeschenIstDieStandardOperationDesKerns(): void
    {
        // Nur eine namentliche Operation (ohne eigenes Array) bekommt von
        // DefaultOperationsListener die DeleteAction-Prüfung angehängt — sonst
        // greift MethodInUseVoter nicht und der Button wird nie ausgegraut.
        //
        // Und das `!` ist kein Schmuck: Es setzt `primary`. Ohne das stünde
        // der Knopf nur im ⋯-Menü, und eine ausgegraute Operation zeichnet
        // das Template dort überhaupt nicht — der Hinweis wäre unsichtbar.
        $operations = $GLOBALS['TL_DCA']['tl_consho_payment_method']['list']['operations'];

        self::assertContains('!delete', $operations);
        self::assertArrayNotHasKey('delete', $operations);
    }
}

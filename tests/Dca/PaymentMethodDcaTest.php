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
        // In practice DataContainerCallbackListener (#[AsHook('loadDataContainer',
        // priority: -16)]) already registers the save_callback from IbanListener before
        // VirtualFieldsMappingListener (priority: -4096) runs. That is reproduced here
        // because this test loads the DCA file in isolation, without running the hooks.
        $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']['iban']['save_callback'] = [[IbanListener::class, '__invoke']];

        // C3: module settings as virtual fields — after an uninstall, own
        // columns stayed behind (Isotope, Merconis).
        (new VirtualFieldsMappingListener())('tl_consho_payment_method');

        $fields = $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields'];

        foreach (self::BANK_FIELDS as $field) {
            self::assertArrayNotHasKey('sql', $fields[$field], $field);
            self::assertSame('jsonData', $fields[$field]['targetColumn'] ?? null, $field);
        }

        self::assertTrue($fields['jsonData']['virtualTarget']);
    }

    /**
     * The Giro code carries at most 70 characters of beneficiary name and must
     * not shorten it (EPC069-12 v3.1, 2.2) — so the form rejects anything longer.
     * The switch sits in the bank details section, not on prepayment.
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
        // It gets a save_callback via #[AsCallback] — and Contao no longer maps a
        // field with a save_callback to jsonData automatically.
        self::assertSame('jsonData', $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']['iban']['targetColumn']);
    }

    public function testKeinVirtuellesFeldTraegtUniqueOderFallback(): void
    {
        // DcaExtractor forbids both on virtual fields.
        foreach (self::BANK_FIELDS as $field) {
            $eval = $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields'][$field]['eval'] ?? [];

            self::assertArrayNotHasKey('unique', $eval, $field);
            self::assertArrayNotHasKey('fallback', $eval, $field);
        }
    }

    /**
     * E-7: when creating a record, Contao sets the default of EVERY field, even
     * of a virtual one from another palette — cash on delivery carried
     * `dueDays: 14` in jsonData. Defaults of virtual fields belong in the
     * handler; the payment term shows its own as a placeholder.
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
        // C5: § 56 (3) ZaDiG 2018 — the form does not invite a surcharge.
        // Since E-1 there is the TAX CHOICE for a fee (Z3), but still no
        // amount field on every payment method; only cash on delivery brings
        // an amount (Z4, E-3) — and only in its palette.
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
        // `instructions` and `net` are reserved keys in the config array
        // (PaymentHandlerInterface) — never a virtual field without `sql`.
        $fields = $GLOBALS['TL_DCA']['tl_consho_payment_method']['fields'];

        self::assertArrayHasKey('sql', $fields['instructions']);

        self::assertTrue(
            !isset($fields['net']) || \array_key_exists('sql', $fields['net']),
            'net',
        );
    }

    /**
     * The secret of the test provider does not appear on read paths.
     * `hideInput` hides it in Contao's detail view and in the version
     * comparison, and contao-ai core from v1.8.1 masks it by the same rule in
     * all read commands.
     */
    public function testDasTestgeheimnisZeigtSichInKeinemLeseweg(): void
    {
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_payment_method']['fields']['testSecret']['eval']['hideInput'] ?? false);
    }

    public function testLoeschenIstDieStandardOperationDesKerns(): void
    {
        // Only a named operation (without an array of its own) gets the
        // DeleteAction check attached by DefaultOperationsListener — otherwise
        // MethodInUseVoter does not take effect and the button is never greyed out.
        //
        // And the `!` is not decoration: it sets `primary`. Without it the
        // button would only stand in the ⋯ menu, and the template does not draw
        // a greyed-out operation there at all — the hint would be invisible.
        $operations = $GLOBALS['TL_DCA']['tl_consho_payment_method']['list']['operations'];

        self::assertContains('!delete', $operations);
        self::assertArrayNotHasKey('delete', $operations);
    }
}

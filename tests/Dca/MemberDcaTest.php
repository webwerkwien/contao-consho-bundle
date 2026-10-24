<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Lieferadresse und UID-Nummer pflegt das Mitglied selbst, in Contaos
 * „Persönliche Daten" und bei der Registrierung — wie die Rechnungsadresse.
 * Was das System schreibt (Prüfergebnis, Nachweis), bleibt dort draußen.
 */
class MemberDcaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_member']['palettes']['default'] = '{address_legend},street;{contact_legend},email';

        require __DIR__.'/../../contao/dca/tl_member.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testLieferadresseUndUidSindImFrontendPflegbar(): void
    {
        $fields = $GLOBALS['TL_DCA']['tl_member']['fields'];
        $shipping = ['consho_shippingCompany', 'consho_shippingFirstname', 'consho_shippingLastname',
            'consho_shippingStreet', 'consho_shippingPostal', 'consho_shippingCity', 'consho_shippingState', 'consho_shippingCountry'];

        foreach ($shipping as $field) {
            self::assertTrue($fields[$field]['eval']['feEditable'] ?? false, $field);
            self::assertSame('consho_shipping', $fields[$field]['eval']['feGroup'] ?? null, $field);
        }

        self::assertTrue($fields['consho_vatId']['eval']['feEditable'] ?? false);
        self::assertSame('consho_tax', $fields['consho_vatId']['eval']['feGroup'] ?? null);
    }

    public function testWasDasSystemSchreibtBleibtImBackend(): void
    {
        $fields = $GLOBALS['TL_DCA']['tl_member']['fields'];

        foreach (['consho_vatIdStatus', 'consho_vatIdCheckedAt', 'consho_vatIdRequestIdentifier', 'consho_confirmedEmail', 'consho_confirmedEmailAt'] as $field) {
            self::assertArrayNotHasKey('feEditable', $fields[$field]['eval'] ?? [], $field);
        }
    }

    public function testDieLieferadresseHatKeineAnrede(): void
    {
        $field = $GLOBALS['TL_DCA']['tl_member']['fields']['consho_shippingSalutation'];

        // Kept as a column for access and erasure, but nowhere to enter it.
        self::assertArrayNotHasKey('inputType', $field);
        self::assertStringNotContainsString('consho_shippingSalutation', $GLOBALS['TL_DCA']['tl_member']['palettes']['default']);
    }

    public function testJedeFeldgruppeHatEineUeberschrift(): void
    {
        foreach (['de', 'en'] as $language) {
            $GLOBALS['TL_LANG'] = [];
            require __DIR__.'/../../contao/languages/'.$language.'/tl_member.php';

            foreach (['consho_shipping', 'consho_tax'] as $group) {
                // ModulePersonalData looks up <feGroup>Details.
                self::assertNotEmpty($GLOBALS['TL_LANG']['tl_member'][$group.'Details'] ?? '', $language.' '.$group);
            }
        }
    }
}

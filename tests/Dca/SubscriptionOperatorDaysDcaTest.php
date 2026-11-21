<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * The shop's lead time for the operator's cancellation and price changes of
 * running subscriptions (Abo-9, P127): a field missing from the palette or a
 * legend without text would only show in the back end.
 */
class SubscriptionOperatorDaysDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_shop.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDasFeldIstEineZahlMitAchtWochenAlsVorgabe(): void
    {
        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['subscriptionOperatorDays'];

        self::assertSame('text', $field['inputType']);
        self::assertSame('natural', $field['eval']['rgxp']);
        self::assertSame('smallint', $field['sql']['type']);
        self::assertSame(56, $field['sql']['default']);
    }

    public function testDasFeldStehtUnterEinerEigenenLegendeNachDemWiderruf(): void
    {
        $palette = $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default'];

        self::assertStringContainsString('{withdrawal_legend},customerType;{subscription_legend},subscriptionOperatorDays;', $palette);
        self::assertSame('withdrawalDays,withdrawalGraceDays', $GLOBALS['TL_DCA']['tl_consho_shop']['subpalettes']['customerType_consumer']);
    }

    public function testDieLegendeUndDasFeldSindInBeidenSprachenBenannt(): void
    {
        foreach (['de', 'en'] as $language) {
            $GLOBALS['TL_LANG'] = [];

            require __DIR__.'/../../contao/languages/'.$language.'/tl_consho_shop.php';

            $labels = $GLOBALS['TL_LANG']['tl_consho_shop'];
            unset($GLOBALS['TL_LANG']);

            self::assertArrayHasKey('subscription_legend', $labels, $language);
            self::assertCount(2, $labels['subscriptionOperatorDays'], $language);
        }
    }
}

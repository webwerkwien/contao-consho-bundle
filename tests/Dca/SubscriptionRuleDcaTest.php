<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionRuleListener;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;

class SubscriptionRuleDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/languages/de/tl_consho_subscription_rule.php';
        require __DIR__.'/../../contao/dca/tl_consho_subscription_rule.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG'], $GLOBALS['BE_MOD']);
    }

    public function testDieVerlaengerungKenntGenauDieWerteDesEnumsUndEndetAlsVorgabe(): void
    {
        $renewal = $GLOBALS['TL_DCA']['tl_consho_subscription_rule']['fields']['renewal'];

        self::assertSame(array_map(static fn (RenewalKind $kind): string => $kind->value, RenewalKind::cases()), $renewal['options']);
        // P7: the type without legal risk.
        self::assertSame('ends', $renewal['sql']['default']);
    }

    public function testDieVorgabenAusDemPlan(): void
    {
        $fields = $GLOBALS['TL_DCA']['tl_consho_subscription_rule']['fields'];

        // P7, and the grace period from A6.
        self::assertSame(14, $fields['noticeDays']['sql']['default']);
        self::assertSame(30, $fields['reminderDays']['sql']['default']);
        self::assertSame(14, $fields['graceDays']['sql']['default']);
        self::assertSame(1, $fields['termLength']['sql']['default']);
        self::assertSame('year', $fields['termUnit']['sql']['default']);
        self::assertSame(0, $fields['billingInterval']['sql']['default']);
    }

    public function testDieRegelTraegtKeinenBetrag(): void
    {
        // A2: the amount is in the product's price table.
        $fields = array_keys($GLOBALS['TL_DCA']['tl_consho_subscription_rule']['fields']);

        self::assertSame([], array_values(array_intersect($fields, ['net', 'amount', 'price', 'currency'])));
    }

    public function testLoeschenLaeuftUeberDieBerechtigungspruefung(): void
    {
        // Named, not as an array — otherwise nobody checks the voter.
        self::assertContains('!delete', $GLOBALS['TL_DCA']['tl_consho_subscription_rule']['list']['operations']);
    }

    public function testDasModulStehtNachDenZahlungsarten(): void
    {
        require __DIR__.'/../../contao/config/config.php';

        $setup = array_keys($GLOBALS['BE_MOD']['consho_setup']);

        self::assertSame(array_search('consho_payment', $setup, true) + 1, array_search('consho_subscription_rule', $setup, true));
        self::assertSame(['tl_consho_subscription_rule'], $GLOBALS['BE_MOD']['consho_setup']['consho_subscription_rule']['tables']);
    }

    public function testEineUngueltigeRegelWirdNichtGespeichert(): void
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 0, 'renewal' => 'ends', 'noticeDays' => 14, 'reminderDays' => 30]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('höchstens 28 Tage');

        // Only the changed fields arrive; the rest comes from the saved record.
        (new SubscriptionRuleListener())->refuseInvalid(['renewal' => 'indefinite', 'noticeDays' => 40, 'reminderDays' => 60], $dc);
    }

    public function testEineGueltigeRegelKommtUnveraendertZurueck(): void
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 0, 'renewal' => 'ends', 'noticeDays' => 14, 'reminderDays' => 30]);

        self::assertSame(['billingInterval' => 1], (new SubscriptionRuleListener())->refuseInvalid(['billingInterval' => 1], $dc));
    }
}

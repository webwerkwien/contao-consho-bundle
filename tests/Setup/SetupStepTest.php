<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Setup;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Setup\SetupStep;

class SetupStepTest extends TestCase
{
    public function testJederSperrendeBefundHatEinenSchritt(): void
    {
        // I: a new lock reason must not slip past the assistant — it would show
        // "open" nowhere.
        foreach (CheckoutReadiness::BLOCKING as $finding) {
            self::assertNotNull(SetupStep::of($finding), $finding->value);
        }
    }

    public function testKeinBefundStehtInZweiSchritten(): void
    {
        $seen = [];

        foreach (SetupStep::cases() as $step) {
            foreach ($step->findings() as $finding) {
                self::assertArrayNotHasKey($finding->value, $seen, \sprintf('%s steht in %s und %s', $finding->value, $seen[$finding->value] ?? '', $step->value));
                $seen[$finding->value] = $step->value;
            }
        }
    }

    public function testSchritteNennenNurWarnungen(): void
    {
        // Notices belong in the dashboard; a setup that counted a duplicate
        // GTIN as unfinished would never be finished.
        foreach (SetupStep::cases() as $step) {
            foreach ($step->findings() as $finding) {
                self::assertTrue($finding->isWarning(), $finding->value);
            }
        }
    }

    public function testEinBetriebsbefundGehoertZuKeinemSchritt(): void
    {
        self::assertNull(SetupStep::of(ConfigurationFinding::StockLow));
        self::assertNull(SetupStep::of(ConfigurationFinding::DuplicateGtin));
    }
}

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
        // Spec I, Auslegung: ein neuer Sperrgrund darf nicht am Assistenten
        // vorbeirutschen — er würde „offen" nirgends zeigen.
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
        // Hinweise gehören ins Dashboard; eine Einrichtung, die eine doppelte
        // GTIN als unfertig zählte, würde nie fertig.
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

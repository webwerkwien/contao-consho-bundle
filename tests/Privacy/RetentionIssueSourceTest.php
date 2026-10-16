<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Privacy;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Privacy\RetentionIssueSource;
use Webwerkwien\ContaoConshoBundle\Privacy\RetentionPolicy;

/**
 * D-5 auf der Startseite: Shop ohne Frist (Warnung), und die Ankündigung
 * der automatischen Anonymisierung 30 Tage vorher (Hinweis).
 */
final class RetentionIssueSourceTest extends TestCase
{
    public function testOhneFristUndAnkuendigung(): void
    {
        $c = PrivacySchema::create();
        $c->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien', 'retentionYears' => 7, 'autoAnonymize' => 1]);
        $c->insert('tl_consho_shop', ['id' => 2, 'title' => 'Ohne', 'retentionYears' => 0]);

        foreach (range(1, 7) as $i) {
            $c->insert('tl_consho_order', ['id' => $i, 'shop' => 1, 'orderNumber' => 'WEB-'.$i, 'createdAt' => strtotime('2019-06-01')]); // Ende 31.12.2026
        }
        $c->insert('tl_consho_order', ['id' => 8, 'shop' => 1, 'orderNumber' => 'WEB-8', 'createdAt' => strtotime('2020-06-01')]);

        $issues = (new RetentionIssueSource($c, new RetentionPolicy($c), static fn (): int => (int) strtotime('2026-12-15')))->issues();

        self::assertCount(2, $issues);
        self::assertSame(ConfigurationFinding::RetentionPeriodMissing, $issues[0]->finding);
        self::assertSame('Ohne', $issues[0]->context['shop']);
        self::assertTrue(ConfigurationFinding::RetentionPeriodMissing->isWarning());

        self::assertSame(ConfigurationFinding::AnonymizationDue, $issues[1]->finding);
        self::assertSame(['shop' => 'Wien', 'count' => '7', 'numbers' => 'WEB-1, WEB-2, WEB-3, WEB-4, WEB-5, …'], $issues[1]->context);
        self::assertFalse(ConfigurationFinding::AnonymizationDue->isWarning());

        self::assertCount(1, (new RetentionIssueSource($c, new RetentionPolicy($c), static fn (): int => (int) strtotime('2026-09-28')))->issues(), 'zu früh: nur die fehlende Frist');
    }
}

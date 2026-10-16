<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\EntitlementListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * F, Abnahme 2026-09-29: Die Liste der Berechtigungen zeigte „4 × 1.0000" —
 * Artikel-ID und rohe Dezimalzahl (Fall 30).
 */
class EntitlementListenerTest extends TestCase
{
    public function testNameMengeEndeUndSperre(): void
    {
        $c = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($c, 'tl_consho_order_item');
        $c->insert('tl_consho_order_item', ['id' => 79, 'productName' => 'E-Book <Kräuter>']);

        $GLOBALS['TL_LANG']['tl_consho_entitlement'] = ['permanent' => 'dauerhaft', 'until' => 'gültig bis %s', 'revokedLabel' => 'gesperrt'];
        $GLOBALS['TL_LANGUAGE'] = 'de';
        $listener = new EntitlementListener($c);

        $permanent = $listener->label(['orderItem' => 79, 'product' => 4, 'quantity' => '1.0000', 'validUntil' => null, 'revoked' => 0]);
        self::assertSame('1 × E-Book &lt;Kräuter&gt; — dauerhaft', $permanent);

        $revoked = $listener->label(['orderItem' => 79, 'product' => 4, 'quantity' => '20.0000', 'validUntil' => null, 'revoked' => 1]);
        self::assertStringStartsWith('20 × ', $revoked);
        self::assertStringContainsString('gesperrt', $revoked);

        // Abo-5 (P38): „ruht seit …", neben „gesperrt" und unabhängig davon.
        $GLOBALS['TL_LANG']['tl_consho_entitlement']['suspendedLabel'] = 'ruht seit %s';
        $GLOBALS['TL_CONFIG']['dateFormat'] = 'd.m.Y';
        $suspended = $listener->label(['orderItem' => 79, 'product' => 4, 'quantity' => '1.0000', 'validUntil' => null, 'revoked' => 0, 'suspendedAt' => 1_790_000_000]);
        self::assertStringContainsString('ruht seit ', $suspended);
        self::assertStringNotContainsString('gesperrt', $suspended);
        self::assertStringNotContainsString('ruht', $listener->label(['orderItem' => 79, 'product' => 4, 'quantity' => '1.0000', 'validUntil' => null, 'revoked' => 0, 'suspendedAt' => null]));

        unset($GLOBALS['TL_LANG']['tl_consho_entitlement'], $GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_CONFIG']['dateFormat']);
    }
}

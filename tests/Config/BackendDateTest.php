<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Config;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;

/**
 * The date format from the installation's settings, not a fixed `d.m.Y`.
 */
final class BackendDateTest extends TestCase
{
    private mixed $saved;

    protected function setUp(): void
    {
        $this->saved = $GLOBALS['TL_CONFIG'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TL_CONFIG'] = $this->saved;
    }

    public function testDasFormatDerEinstellungen(): void
    {
        $GLOBALS['TL_CONFIG'] = ['dateFormat' => 'Y-m-d', 'datimFormat' => 'Y-m-d H:i'];
        $at = (new \DateTimeImmutable('2026-09-30 14:05'))->getTimestamp();

        self::assertSame('2026-09-30', BackendDate::format($at));
        self::assertSame('2026-09-30 14:05', BackendDate::withTime($at));
    }

    public function testOhneEinstellungenDeutsch(): void
    {
        $GLOBALS['TL_CONFIG'] = [];
        $at = (new \DateTimeImmutable('2026-09-30 14:05'))->getTimestamp();

        self::assertSame('30.09.2026', BackendDate::format($at));
        self::assertSame('30.09.2026 14:05', BackendDate::withTime($at));
    }
}

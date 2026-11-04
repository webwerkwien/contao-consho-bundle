<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\WebhookReference;

class WebhookReferenceTest extends TestCase
{
    public function testAboutTrimsAndIsNotIgnored(): void
    {
        $about = WebhookReference::about('  ORDER-1 ');

        self::assertSame('ORDER-1', $about->reference);
        self::assertSame(0, $about->attemptId);
        self::assertFalse($about->isIgnored());
    }

    public function testAnAttemptAloneIsEnough(): void
    {
        self::assertSame(5, WebhookReference::about('', 5)->attemptId);
    }

    public function testNeitherReferenceNorAttemptIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WebhookReference::about(' ', 0);
    }

    public function testIgnoredAlwaysCarriesAReason(): void
    {
        self::assertTrue(WebhookReference::ignored('')->isIgnored());
        self::assertSame('another installation', WebhookReference::ignored('another installation')->ignoredBecause);
    }
}

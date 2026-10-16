<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Token;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;
use Webwerkwien\ContaoConshoBundle\Token\TokenRepositoryInterface;

class TokenGeneratorTest extends TestCase
{
    public function testReturnsAPlaintextTokenOfUsableLength(): void
    {
        $generator = new TokenGenerator($this->createMock(TokenRepositoryInterface::class));

        $token = $generator->issue(1, TokenPurpose::ViewOrder);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTwoTokensAreNeverTheSame(): void
    {
        $generator = new TokenGenerator($this->createMock(TokenRepositoryInterface::class));

        $tokens = [];

        for ($i = 0; $i < 100; ++$i) {
            $tokens[] = $generator->issue(1, TokenPurpose::ViewOrder);
        }

        self::assertCount(100, array_unique($tokens));
    }

    /**
     * The core of the whole design. A database dump must not hand anyone the
     * ability to view someone else's order, trigger a withdrawal or fetch a
     * download.
     */
    public function testStoresOnlyTheHashNeverThePlaintext(): void
    {
        $stored = null;

        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository
            ->method('store')
            ->willReturnCallback(static function (int $o, TokenPurpose $p, string $hash) use (&$stored): void {
                $stored = $hash;
            })
        ;

        $generator = new TokenGenerator($repository);

        $plaintext = $generator->issue(1, TokenPurpose::ViewOrder);

        self::assertNotSame($plaintext, $stored);
        self::assertSame(hash('sha256', $plaintext), $stored);
    }

    /**
     * D-6, T1: the link in a mail lives 90 days. The withdrawal does not hang
     * on it any more (the element "Vertrag widerrufen" takes number and
     * address), and an expired link can be renewed (T4).
     */
    public function testTheMailedOrderPageTokenLivesNinetyDays(): void
    {
        $before = time();
        $expiry = $this->capturedExpiry(TokenPurpose::ViewOrder, null, true);

        self::assertGreaterThanOrEqual($before + 90 * 86400, $expiry);
        self::assertLessThanOrEqual(time() + 90 * 86400, $expiry);
    }

    /**
     * D-6, T2: a token in a redirect ends up in the browser history. A day,
     * then the history entry of a shared computer opens nothing.
     */
    public function testTheOrderPageTokenOfARedirectLivesADay(): void
    {
        $before = time();
        $expiry = $this->capturedExpiry(TokenPurpose::ViewOrder);

        self::assertGreaterThanOrEqual($before + 86400, $expiry);
        self::assertLessThanOrEqual(time() + 86400, $expiry);
    }

    public function testADownloadTokenExpires(): void
    {
        self::assertGreaterThan(time(), $this->capturedExpiry(TokenPurpose::Download));
    }

    /**
     * An explicit expiry has to win over the default, or a shop could never
     * shorten a download window for a single order.
     */
    public function testAnExplicitExpiryOverridesTheDefault(): void
    {
        $expiry = time() + 60;

        self::assertSame($expiry, $this->capturedExpiry(TokenPurpose::Download, $expiry));
    }

    private function capturedExpiry(TokenPurpose $purpose, int|null $expiresAt = null, bool $mailed = false): int|null
    {
        $captured = null;

        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository
            ->method('store')
            ->willReturnCallback(
                static function (int $o, TokenPurpose $p, string $h, int $c, int|null $e) use (&$captured): void {
                    $captured = $e;
                },
            )
        ;

        (new TokenGenerator($repository))->issue(1, $purpose, $expiresAt, $mailed);

        return $captured;
    }
}

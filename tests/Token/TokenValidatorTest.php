<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Token;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;
use Webwerkwien\ContaoConshoBundle\Token\TokenRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Token\TokenValidator;

class TokenValidatorTest extends TestCase
{
    private const VALID_SHAPE = 'a';

    public function testAValidTokenYieldsItsOrderId(): void
    {
        $validator = $this->validatorFinding(['pid' => 7, 'expiresAt' => null, 'usedAt' => null]);

        self::assertSame(7, $validator->validate($this->token(), TokenPurpose::ViewOrder));
    }

    public function testAnUnknownTokenYieldsNull(): void
    {
        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository->method('findByHash')->willReturn(null);

        $validator = new TokenValidator($repository);

        self::assertNull($validator->validate($this->token('b'), TokenPurpose::ViewOrder));
    }

    public function testAnExpiredTokenYieldsNull(): void
    {
        $validator = $this->validatorFinding(['pid' => 7, 'expiresAt' => time() - 1, 'usedAt' => null]);

        self::assertNull($validator->validate($this->token(), TokenPurpose::Download));
    }

    public function testATokenExpiringInTheFutureIsStillValid(): void
    {
        $validator = $this->validatorFinding(['pid' => 7, 'expiresAt' => time() + 60, 'usedAt' => null]);

        self::assertSame(7, $validator->validate($this->token(), TokenPurpose::Download));
    }

    /**
     * The order page's link carries no expiry at all — § 13a FAGG wants the
     * withdrawal button on it reachable throughout the period. null must not
     * be mistaken for "expired in 1970", which is exactly what a 0 in that
     * column would have meant.
     */
    public function testATokenWithoutExpiryStaysValid(): void
    {
        $validator = $this->validatorFinding(['pid' => 7, 'expiresAt' => null, 'usedAt' => null]);

        self::assertSame(7, $validator->validate($this->token(), TokenPurpose::ViewOrder));
    }

    /**
     * Not a micro-optimisation. A publicly reachable endpoint that runs a query
     * for EVERY input is a convenient tool for anyone wanting to take the site
     * down.
     */
    public function testAMalformedTokenIsRejectedWithoutHittingTheDatabase(): void
    {
        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository->expects(self::never())->method('findByHash');

        $validator = new TokenValidator($repository);

        self::assertNull($validator->validate('', TokenPurpose::ViewOrder));
        self::assertNull($validator->validate('short', TokenPurpose::ViewOrder));
        self::assertNull($validator->validate($this->token('z'), TokenPurpose::ViewOrder));
        self::assertNull($validator->validate($this->token().'a', TokenPurpose::ViewOrder));

        // PCRE lässt bei $ einen abschließenden Zeilenumbruch durch. 64 gültige
        // Zeichen plus \n hätten die Vorprüfung bestanden und die Abfrage
        // ausgelöst — genau das, was sie verhindern soll.
        self::assertNull($validator->validate($this->token()."\n", TokenPurpose::ViewOrder));
    }

    /**
     * A download token must not open the order page, however genuine it is.
     * The purpose is part of the lookup, not checked afterwards.
     */
    public function testTheLookupIsScopedToThePurpose(): void
    {
        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('findByHash')
            ->with(self::anything(), TokenPurpose::ViewOrder)
            ->willReturn(null)
        ;

        (new TokenValidator($repository))->validate($this->token(), TokenPurpose::ViewOrder);
    }

    public function testThePlaintextIsNeverPassedToTheRepository(): void
    {
        $plaintext = $this->token();

        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('findByHash')
            ->with(hash('sha256', $plaintext))
            ->willReturn(null)
        ;

        (new TokenValidator($repository))->validate($plaintext, TokenPurpose::ViewOrder);
    }

    private function token(string $char = self::VALID_SHAPE): string
    {
        return str_repeat($char, 64);
    }

    /**
     * @param array{pid: int, expiresAt: int|null, usedAt: int|null} $row
     */
    private function validatorFinding(array $row): TokenValidator
    {
        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository->method('findByHash')->willReturn($row);

        return new TokenValidator($repository);
    }
}

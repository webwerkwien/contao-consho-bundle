<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Mail\SecretCipher;

class SecretCipherTest extends TestCase
{
    private SecretCipher $cipher;

    protected function setUp(): void
    {
        // 32 bytes, as sodium_crypto_secretbox requires.
        $this->cipher = new SecretCipher(str_repeat('k', 32));
    }

    public function testEncryptsAndDecryptsRoundTrip(): void
    {
        $encrypted = $this->cipher->encrypt('hunter2');

        self::assertSame('hunter2', $this->cipher->decrypt($encrypted));
    }

    public function testTheCiphertextNeverContainsThePlaintext(): void
    {
        self::assertStringNotContainsString('hunter2', $this->cipher->encrypt('hunter2'));
    }

    /**
     * A fresh nonce every time. Identical ciphertexts would tell anyone with
     * database access which shops share a password — without decrypting
     * anything.
     */
    public function testTwoEncryptionsOfTheSameValueDiffer(): void
    {
        self::assertNotSame($this->cipher->encrypt('hunter2'), $this->cipher->encrypt('hunter2'));
    }

    public function testAnEmptyValueStaysEmpty(): void
    {
        self::assertSame('', $this->cipher->encrypt(''));
        self::assertSame('', $this->cipher->decrypt(''));
    }

    /**
     * secretbox authenticates as well as encrypts. A password that came back
     * altered would be sent to the mail server as a login attempt — repeatedly,
     * until the account is locked.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        $encrypted = $this->cipher->encrypt('hunter2');
        $tampered = substr($encrypted, 0, -4).'AAAA';

        self::assertNull($this->cipher->decrypt($tampered));
    }

    public function testRubbishThatIsNotEvenBase64IsRejected(): void
    {
        self::assertNull($this->cipher->decrypt('not base64 at all !!!'));
    }

    /**
     * Too short a value cannot hold nonce and ciphertext at once. Without this
     * guard the substr below would silently produce a wrong nonce instead of an
     * error.
     */
    public function testCiphertextShorterThanTheNonceIsRejected(): void
    {
        self::assertNull($this->cipher->decrypt(base64_encode('short')));
    }

    public function testAKeyOfTheWrongLengthIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SecretCipher('too short');
    }
}

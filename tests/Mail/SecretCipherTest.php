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

    /**
     * Refused at use, not at construction (v0.1.2). Consho's terminate
     * listeners build the mail services on every request and every console
     * command; a check in the constructor took down Contao's post-update
     * script on an installation without the key (the agency site,
     * 2026-10-16), long before anything wanted to send mail.
     */
    public function testAKeyOfTheWrongLengthIsRefusedWhenUsed(): void
    {
        $cipher = new SecretCipher('too short');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CONSHO_SECRET_KEY');

        $cipher->encrypt('hunter2');
    }

    /**
     * What `%env(base64:default::CONSHO_SECRET_KEY)%` hands over when the
     * variable is not set: null, not '' as the service definition once said.
     */
    public function testWithoutAKeyItConstructsAndRefusesOnlyRealWork(): void
    {
        $cipher = new SecretCipher(null);

        // Nothing to protect: no key needed, nothing thrown.
        self::assertSame('', $cipher->encrypt(''));
        self::assertSame('', $cipher->decrypt(''));

        try {
            $cipher->decrypt(base64_encode(str_repeat('x', 40)));
            self::fail('a stored password without a key must not pass silently');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('CONSHO_SECRET_KEY', $e->getMessage());
        }
    }

    /**
     * N2 (Nachlese Stufe 2, Michael 2026-10-17: „ja, ableiten"): Ohne
     * CONSHO_SECRET_KEY kommt der Schlüssel aus APP_SECRET, das Contao bei der
     * Installation selbst anlegt — ein Betreiber ohne SSH kam sonst nie zu
     * einem SMTP-Passwort.
     */
    public function testOhneEigenenSchluesselLeitetErAusAppSecretAb(): void
    {
        $cipher = new SecretCipher(null, 'app-secret-of-this-installation');

        $stored = $cipher->encrypt('hunter2');

        self::assertSame('hunter2', (new SecretCipher(null, 'app-secret-of-this-installation'))->decrypt($stored), 'derselbe Schlüssel bei jedem Aufbau');
        self::assertNull((new SecretCipher(null, 'ein anderes'))->decrypt($stored), 'ein neues APP_SECRET liest nichts mehr');
    }

    /**
     * Fable-Review (2026-10-17): Zweckangabe, Hash und Länge der Ableitung
     * waren durch keinen Test festgelegt — „v1" → „v2" überlebte die ganze
     * Suite. Jede solche Änderung ist eine stille Schlüsselrotation: alle aus
     * APP_SECRET abgeleiteten Passwörter wären unlesbar. Dieser Chiffretext
     * entstand einmal mit der Ableitung von 0.1.3 und muss lesbar bleiben.
     */
    public function testEinMitDerAbleitungVon013GespeicherterWertBleibtLesbar(): void
    {
        self::assertSame(
            'hunter2',
            (new SecretCipher(null, 'known-answer-secret'))->decrypt('BwcHBwcHBwcHBwcHBwcHBwcHBwcHBwcH+tYcq/GTwDNga0KKe4TUVGdeZg54nSM='),
        );
    }

    public function testDerEigeneSchluesselHatVorrang(): void
    {
        $own = new SecretCipher(str_repeat('k', 32), 'app-secret');

        self::assertSame('hunter2', (new SecretCipher(str_repeat('k', 32)))->decrypt($own->encrypt('hunter2')), 'APP_SECRET spielt keine Rolle');
    }

    /**
     * Ein gesetzter, aber falscher Consho-Schlüssel fällt NICHT still auf
     * APP_SECRET zurück: Die gespeicherten Passwörter wären dann unlesbar, und
     * niemand wüsste, warum.
     */
    public function testEinFalscherEigenerSchluesselFaelltNichtZurueck(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CONSHO_SECRET_KEY');

        (new SecretCipher('too short', 'app-secret'))->encrypt('hunter2');
    }

    public function testOhneBeidesNenntDieMeldungBeide(): void
    {
        try {
            (new SecretCipher(null, ''))->encrypt('hunter2');
            self::fail('ohne Schlüssel nichts verschlüsseln');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('CONSHO_SECRET_KEY', $e->getMessage());
            self::assertStringContainsString('APP_SECRET', $e->getMessage());
        }
    }
}

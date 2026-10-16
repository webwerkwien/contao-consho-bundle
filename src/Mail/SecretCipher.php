<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

/**
 * Encrypts mail credentials at rest.
 *
 * The key comes from the environment, never from the database — otherwise the
 * lock would sit next to the key and a dump would hand over both at once.
 *
 * secretbox both encrypts and authenticates, which is why tampering is
 * detectable at all. A fresh nonce per encryption means two shops with the same
 * password do not end up with identical ciphertexts: without that, a dump would
 * reveal which shops share a password without decrypting a single byte.
 */
final class SecretCipher
{
    private readonly string $key;

    /**
     * @param string|null $key null when CONSHO_SECRET_KEY is not set — what
     *                         `%env(base64:default::…)%` hands over then
     */
    public function __construct(string|null $key)
    {
        /*
         * Checked at use, not here (v0.1.2). Consho's terminate listeners
         * build the mail services on every request and every console command,
         * so a check in the constructor took down Contao's post-update script
         * on an installation without the key — before anything wanted to send
         * mail. A wrong key still fails with a message naming the variable,
         * at the first value that needs it.
         */
        $this->key = $key ?? '';
    }

    public function encrypt(string $plaintext): string
    {
        // An empty password stays empty rather than becoming ciphertext that
        // decrypts to nothing — "no password set" has to stay recognisable.
        if ('' === $plaintext) {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key()));
    }

    /**
     * @return string|null null when the value cannot be trusted
     *
     * Null rather than an exception: a broken row must degrade to "this shop
     * cannot send mail", not to a fatal error on every back end page that
     * happens to load the transport.
     */
    public function decrypt(string $ciphertext): string|null
    {
        if ('' === $ciphertext) {
            return '';
        }

        $raw = base64_decode($ciphertext, true);

        // Strict base64, and long enough to hold a nonce AND a payload. Without
        // the length guard the substr below would hand sodium a truncated nonce
        // instead of failing here.
        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($payload, $nonce, $this->key());

        return false === $plaintext ? null : $plaintext;
    }

    /**
     * A key of the wrong length fails inside sodium with an unhelpful message;
     * this one names the variable and how to make one.
     */
    private function key(): string
    {
        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($this->key)) {
            throw new \InvalidArgumentException(\sprintf(
                'The encryption key must be exactly %d bytes: set CONSHO_SECRET_KEY in .env.local to %d base64-encoded random bytes (php -r "echo base64_encode(random_bytes(%d));").',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }

        return $this->key;
    }
}

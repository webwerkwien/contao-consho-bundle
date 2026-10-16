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
    public function __construct(private readonly string $key)
    {
        /*
         * Checked here rather than at first use. A key of the wrong length
         * fails inside sodium with an unhelpful message, at the moment someone
         * is trying to send mail — the wrong place to learn that an environment
         * variable is mis-set.
         */
        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException(\sprintf(
                'The encryption key must be exactly %d bytes.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }
    }

    public function encrypt(string $plaintext): string
    {
        // An empty password stays empty rather than becoming ciphertext that
        // decrypts to nothing — "no password set" has to stay recognisable.
        if ('' === $plaintext) {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
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

        $plaintext = sodium_crypto_secretbox_open($payload, $nonce, $this->key);

        return false === $plaintext ? null : $plaintext;
    }
}

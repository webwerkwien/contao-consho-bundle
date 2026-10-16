<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Mail\SecretCipher;

/**
 * Encrypts the two secrets of a mail transport on their way into the database.
 *
 * ⚠️ Without this, SecretCipher would be pointless. TransportDsnBuilder
 * decrypts what it reads, but nothing was encrypting it — the credentials would
 * sit in the clear in the table and every DSN would come out without a
 * password.
 *
 * ── Why each field needs BOTH callbacks ───────────────────────────────────
 *
 * `hideInput` renders a password box, but it renders it WITH the stored value.
 * Two consequences, and each needs one of the callbacks:
 *
 *  - The encrypted blob would travel to the browser and stand in the page
 *    source. The load callback blanks the field so it never leaves the server.
 *  - The stored value would be posted back on every save and encrypted a
 *    second time, so the next decryption would return base64 rubbish. The save
 *    callback therefore treats an empty submission as "leave it alone".
 *
 * The trade-off of that last rule: a password cannot be emptied by clearing the
 * box, because clearing it is how you say "keep". Deleting the transport is the
 * way to get rid of the credential, and the field description says so.
 */
class TransportSecretListener
{
    public function __construct(private readonly SecretCipher $cipher)
    {
    }

    #[AsCallback(table: 'tl_consho_mail_transport', target: 'fields.password.load')]
    public function blankPassword(): string
    {
        return '';
    }

    #[AsCallback(table: 'tl_consho_mail_transport', target: 'fields.clientSecret.load')]
    public function blankClientSecret(): string
    {
        return '';
    }

    #[AsCallback(table: 'tl_consho_mail_transport', target: 'fields.password.save')]
    public function savePassword(mixed $value, DataContainer $dc): string
    {
        return $this->keepOrEncrypt((string) $value, 'password', $dc);
    }

    #[AsCallback(table: 'tl_consho_mail_transport', target: 'fields.clientSecret.save')]
    public function saveClientSecret(mixed $value, DataContainer $dc): string
    {
        return $this->keepOrEncrypt((string) $value, 'clientSecret', $dc);
    }

    private function keepOrEncrypt(string $submitted, string $field, DataContainer $dc): string
    {
        if ('' === $submitted) {
            /*
             * getCurrentRecord(), not getActiveRecord(): the first returns the
             * STORED row, the second merges the submitted values over it. Here
             * the stored value is exactly what is wanted — the submitted one is
             * the empty string that brought us into this branch.
             */
            return (string) ($dc->getCurrentRecord()[$field] ?? '');
        }

        return $this->cipher->encrypt($submitted);
    }
}

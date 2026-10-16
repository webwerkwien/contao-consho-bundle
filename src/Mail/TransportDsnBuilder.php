<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

/**
 * Turns a stored transport row into a Symfony Mailer DSN.
 *
 * Credentials are decrypted here and nowhere else, and every value is URL
 * encoded — a password containing "@" or ":" would otherwise tear the DSN apart
 * in a way that looks like a wrong host.
 */
final class TransportDsnBuilder
{
    public function __construct(private readonly SecretCipher $cipher)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function build(array $row): string
    {
        return match ((string) ($row['type'] ?? '')) {
            'smtp' => $this->smtp($row),
            'graph' => $this->graph($row),
            'sendmail' => 'sendmail://default',
            default => throw UnsupportedTransportType::for((string) ($row['type'] ?? '')),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function smtp(array $row): string
    {
        /*
         * How the encryption field reaches Symfony, read off
         * EsmtpTransportFactory in mailer 7.4:
         *
         *   smtps://              -> $tls = true   implicit TLS, usually 465
         *   smtp://               -> $tls = null   STARTTLS if the server offers it
         *   smtp://?auto_tls=false-> $tls = false  no encryption at all
         *
         * Without this the field would be a control that does nothing, which is
         * worse than having no field: the operator picks TLS, believes it, and
         * is wrong.
         */
        $encryption = (string) ($row['encryption'] ?? 'tls');
        $scheme = 'ssl' === $encryption ? 'smtps' : 'smtp';
        $query = 'none' === $encryption ? '?auto_tls=false' : '';

        $username = (string) ($row['username'] ?? '');
        $password = $this->decrypt((string) ($row['password'] ?? ''));

        /*
         * No credentials, no user info. A relay on the same host often wants no
         * authentication, and "smtp://:@host" is not the same thing — Symfony
         * reads it as credentials that happen to be empty and offers them.
         */
        $credentials = '' === $username && '' === $password
            ? ''
            : \sprintf('%s:%s@', rawurlencode($username), rawurlencode($password));

        return \sprintf(
            '%s://%s%s:%d%s',
            $scheme,
            $credentials,
            (string) ($row['host'] ?? ''),
            (int) ($row['port'] ?? 587),
            $query,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function graph(array $row): string
    {
        $secret = $this->decrypt((string) ($row['clientSecret'] ?? ''));

        /*
         * 🔴 "microsoftgraph+api" and "tenantId", read off
         * MicrosoftGraphTransportFactory in symfony/microsoft-graph-mailer
         * 8.1. Until C-9 this said "microsoft-graph+api" and "tenant" — a
         * scheme no factory knows, so not one Graph mail could have left, and
         * nothing noticed because nothing had ever sent one.
         */
        return \sprintf(
            'microsoftgraph+api://%s:%s@default?tenantId=%s',
            rawurlencode((string) ($row['clientId'] ?? '')),
            rawurlencode($secret),
            rawurlencode((string) ($row['tenantId'] ?? '')),
        );
    }

    private function decrypt(string $ciphertext): string
    {
        $plaintext = $this->cipher->decrypt($ciphertext);

        if (null === $plaintext) {
            /*
             * Connecting without the password would produce an authentication
             * error that looks exactly like a wrong password — and nobody would
             * think of the key in .env having changed. Say the real cause here,
             * where it is still knowable.
             */
            throw new \RuntimeException(
                'A stored mail credential could not be decrypted. Has the encryption key in .env changed? '
                .'Re-enter the credentials for this transport.',
            );
        }

        return $plaintext;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A stored mail configuration, turned into something that sends (C-9).
 *
 * ── Why a time limit, and why here ───────────────────────────────────────
 *
 * The confirmation goes out in the request of the purchase click, after the
 * commit. Symfony Mailer has no DSN option for a timeout (EsmtpTransportFactory
 * in 7.4 reads auto_tls, source_ip, verify_peer, peer_fingerprint,
 * local_domain and three thresholds). Without one,
 * SocketStream falls back to default_socket_timeout, 60 seconds on most hosts,
 * and a mail server that does not answer keeps the buyer staring at a
 * spinning button for a minute after the order already exists.
 *
 * Ten seconds is generous for a handshake and short enough that a dead server
 * shows up as "not sent" (and "Erneut senden") rather than as a hung shop.
 *
 * ── Why the HTTP client is passed ────────────────────────────────────────
 *
 * The Graph bridge talks HTTP, and its factory hands the client straight to
 * TokenManager, which does not accept null. Transport::fromDsn() without a
 * client is a TypeError for every Graph configuration.
 *
 * No event dispatcher: these are the shop's transports, not Contao's
 * mailer, and Contao's mailer listeners have no business rewriting a
 * sender the operator configured and the deliverability check verified.
 */
final class MailTransportFactory implements MailTransportFactoryInterface
{
    public const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly TransportDsnBuilder $dsns,
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * @param array<string, mixed> $row a tl_consho_mail_transport row
     */
    public function create(array $row): TransportInterface
    {
        $client = $this->client->withOptions([
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => 2 * self::TIMEOUT_SECONDS,
        ]);

        $transport = Transport::fromDsn($this->dsns->build($row), null, $client);

        if ($transport instanceof SmtpTransport) {
            $stream = $transport->getStream();

            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::TIMEOUT_SECONDS);
            }
        }

        return $transport;
    }
}

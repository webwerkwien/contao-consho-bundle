<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

use Symfony\Component\Mime\Address;

/**
 * The From of a mail configuration, its name decoded.
 *
 * Contao stores what the mask takes with its input encoding: "Müller & Co"
 * arrives as "Müller &amp; Co", brackets as "&#40;". In a mail header that is
 * what the customer reads — found at the test mail on the sales shop
 * (Nachlese Stufe 2), where the configuration's title came out as
 * "&#40;…&#41;" once the mask had saved it. The name of the sender went the
 * same way into every customer mail. One place, so the next mail cannot
 * forget it.
 */
final class SenderAddress
{
    /**
     * @param array<string, mixed> $transportRow a row of tl_consho_mail_transport
     */
    public static function of(array $transportRow): Address
    {
        return new Address((string) ($transportRow['senderAddress'] ?? ''), self::decode((string) ($transportRow['senderName'] ?? '')));
    }

    /**
     * An operator text from the mask, as plain text.
     */
    public static function decode(string $stored): string
    {
        return html_entity_decode($stored, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}

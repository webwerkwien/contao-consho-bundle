<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

/**
 * Decides who receives a notification, and in which language.
 *
 * Customer mails follow the language of the ORDER, not of whoever triggers the
 * send. An invoice mail resent a year later must go out in the language the
 * customer ordered in. Operator mails follow the shop: a Viennese shop does not
 * want its own copy in Portuguese because a customer from Lisbon ordered.
 */
final class RecipientResolver
{
    /**
     * Used when neither the order nor the shop names a language.
     *
     * A hard fallback rather than an empty string: an empty locale ends up
     * resolving to whatever the server is set to, which is how a German
     * customer receives an English mail on one host and a German one on the
     * next.
     */
    private const FALLBACK_LANGUAGE = 'en';

    /**
     * @param array{email: string, firstname: string, lastname: string, language: string} $order
     *
     * @return list<Recipient>
     */
    public function forCustomer(array $order): array
    {
        $email = trim($order['email']);

        // No address, no recipient — an empty list rather than a Recipient the
        // mailer would refuse later, further from the cause.
        if ('' === $email) {
            return [];
        }

        return [new Recipient(
            $email,
            trim($order['firstname'].' '.$order['lastname']),
            $order['language'] ?: self::FALLBACK_LANGUAGE,
        )];
    }

    /**
     * The OPERATOR's own copy — so this reads operatorAddress, not the sender.
     *
     * The two used to be one field, which is how a shop ends up writing to
     * itself under a domain it never authenticated. The sender now lives on the
     * mail configuration, where SPF and DMARC are checked against it; this is
     * merely where the copies land.
     *
     * @param array{operatorAddress: string, operatorName: string} $shop
     *
     * @return list<Recipient>
     */
    public function forOperator(array $shop, string $shopLanguage): array
    {
        $email = trim($shop['operatorAddress']);

        if ('' === $email) {
            return [];
        }

        return [new Recipient($email, trim($shop['operatorName']), $shopLanguage ?: self::FALLBACK_LANGUAGE)];
    }
}

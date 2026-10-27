<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A note at the top of an order whose digital goods went to an address
 * nobody has confirmed (Nachlese E4, spec F) — a note,
 * no lock.
 *
 * For digital goods the mail IS the way of delivery (spec A): a mistyped
 * address means the customer paid and never received anything, and the
 * operator only learns of it from the complaint. Nothing is blocked — the
 * customer may well have the mail — but the operator should know before
 * answering "it was sent".
 *
 * Confirmed means either: the order page was used through the link from
 * the mail (`emailConfirmedAt`, D-4), or the member confirmed this very
 * address (`consho_confirmedEmail`, K3). Only orders with entitlements count —
 * those are the ones whose goods travel by mail.
 */
final class DigitalAddressNoticeListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'config.onload')]
    public function __invoke(DataContainer $dc): void
    {
        $query = $this->requests->getCurrentRequest()?->query;

        if (null === $query || 'edit' !== $query->getString('act') || (int) $dc->id < 1) {
            return;
        }

        $email = $this->unconfirmedAddress((int) $dc->id);

        if (null === $email) {
            return;
        }

        $this->framework->getAdapter(Message::class)->addInfo(\sprintf(
            $GLOBALS['TL_LANG']['tl_consho_order']['digitalAddressUnconfirmed'] ?? 'The digital goods of this order went to %s, an address nobody has confirmed yet.',
            StringUtil::specialchars($email),
        ));
    }

    /**
     * The order's address, where it carries entitlements and nobody has
     * confirmed it; null otherwise.
     */
    public function unconfirmedAddress(int $orderId): string|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT o.email, o.emailConfirmedAt, m.consho_confirmedEmail
               FROM tl_consho_order o
          LEFT JOIN tl_member m ON m.id = o.member
              WHERE o.id = ?
                AND EXISTS (SELECT 1 FROM tl_consho_entitlement e WHERE e.pid = o.id)',
            [$orderId],
        );

        if (false === $row || null !== $row['emailConfirmedAt'] || '' === (string) $row['email']) {
            return null;
        }

        if ('' !== (string) $row['consho_confirmedEmail'] && mb_strtolower((string) $row['email']) === (string) $row['consho_confirmedEmail']) {
            return null;
        }

        return (string) $row['email'];
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Date;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\Order\ServicePerformed;

/**
 * Services in the delivery list: everything that fulfils an order is there.
 *
 * Goods are reported there ("Als versandt melden"), and the release of a
 * service or digital line already leaves an electronic delivery there. Only
 * "Leistung erbracht" sat apart, at the order's lines — two steps in two
 * places, the second looking like a conclusion it is not: it only sets when
 * the right of withdrawal ends where the customer consented to the early
 * start (ServicePerformed).
 *
 *  - At a reported delivery, each service line it carries: "Leistung
 *    erbracht" where ServicePerformed would take it, or when it was.
 *  - Above the list, each service or digital line not released yet: when
 *    it will be. Without it the list was empty and "Neue Lieferung" gone
 *    (DeliveryCreateVoter), with nothing saying why.
 */
final class DeliveryServiceListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ServicePerformed $performed,
        private readonly DigitalRelease $release,
        private readonly ContaoCsrfTokenManager $tokens,
        private readonly UrlGeneratorInterface $router,
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * The service lines of one delivery, for its row in the list.
     */
    public function rowSuffix(int $deliveryId): string
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery'] ?? [];
        $parts = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT DISTINCT i.* FROM tl_consho_order_item i
             INNER JOIN tl_consho_delivery_item di ON di.orderItem = i.id
             INNER JOIN tl_consho_delivery d ON d.id = di.pid
             WHERE d.id = ? AND d.reportedAt IS NOT NULL AND i.lineType = 'product' AND i.kind = ?
             ORDER BY i.sorting, i.id",
            [$deliveryId, ProductKind::Service->value],
        ) as $item) {
            $name = StringUtil::specialchars((string) $item['productName']);

            if (null !== $item['performedAt'] && '' !== (string) $item['performedAt']) {
                $parts[] = \sprintf((string) ($lang['servicePerformedOn'] ?? '%s: %s'), $name, Date::parse(Date::getNumericDateFormat(), (int) $item['performedAt']));

                continue;
            }

            if (ServicePerformed::PERFORMED !== $this->performed->preview($item)) {
                continue;
            }

            $url = $this->router->generate('contao_backend', [
                'do' => 'consho_order',
                'table' => 'tl_consho_order_item',
                'key' => 'servicePerformed',
                'id' => (int) $item['id'],
                'rt' => $this->tokens->getDefaultTokenValue(),
            ]);
            $confirm = (string) ($lang['servicePerformedConfirm'] ?? 'OK?');

            $parts[] = \sprintf(
                '%s: <a href="%s" class="tl_submit" style="padding:2px 8px" title="%s" onclick="return confirm(%s)">%s</a>',
                $name,
                StringUtil::specialchars($url),
                StringUtil::specialchars(\sprintf((string) ($lang['servicePerformedTitle'] ?? '%s'), (string) $item['productName'])),
                StringUtil::specialchars(json_encode($confirm, JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)),
                StringUtil::specialchars((string) ($lang['servicePerformed'] ?? 'Leistung erbracht')),
            );
        }

        return [] === $parts ? '' : ' — '.implode(' · ', $parts);
    }

    /**
     * When each service or digital line still waiting will be released — on
     * the list of one order's deliveries only.
     */
    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onload')]
    public function releaseNotes(DataContainer|null $dc = null): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request || !$request->isMethodSafe()) {
            return;
        }

        $query = $request->query;
        $orderId = $query->getInt('id');

        if ($query->has('act') || $query->has('key') || $orderId < 1 || 'tl_consho_delivery' !== $query->getString('table')) {
            return;
        }

        foreach ($this->notes($orderId) as $note) {
            $this->framework->getAdapter(Message::class)->addInfo($note);
        }
    }

    /**
     * @return list<string>
     */
    public function notes(int $orderId): array
    {
        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);

        if (false === $order || null !== $order['cancelledAt']) {
            return [];
        }

        $waiting = $this->connection->fetchAllAssociative(
            "SELECT i.productName, i.kind FROM tl_consho_order_item i
             WHERE i.pid = ? AND i.lineType = 'product' AND i.kind IN (?, ?)
               AND NOT EXISTS (SELECT 1 FROM tl_consho_entitlement e WHERE e.orderItem = i.id)
             ORDER BY i.sorting, i.id",
            [$orderId, ProductKind::Service->value, ProductKind::Digital->value],
        );

        if ([] === $waiting) {
            return [];
        }

        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery'] ?? [];
        $dueAt = $this->release->dueAt($order);
        $notes = [];

        foreach ($waiting as $item) {
            $key = match (true) {
                null === $dueAt => 'releaseAfterPayment',
                $dueAt <= time() => 'releaseDue',
                default => 'releaseOn',
            };

            // Escaped: Contao prints messages as HTML.
            $note = \sprintf((string) ($lang[$key] ?? '%s'), StringUtil::specialchars((string) $item['productName']), null === $dueAt ? '' : Date::parse(Date::getNumericDateFormat(), $dueAt));

            if (ProductKind::Service->value === (string) $item['kind']) {
                $note .= ' '.(string) ($lang['releaseThenPerformed'] ?? '');
            }

            $notes[] = trim($note);
        }

        return $notes;
    }
}

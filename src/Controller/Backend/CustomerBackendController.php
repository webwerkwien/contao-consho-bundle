<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Customer\Customer;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerDirectory;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Privacy\CustomerErasure;
use Webwerkwien\ContaoConshoBundle\Privacy\CustomerExport;

/**
 * The menu item "Kunden" (D-4, K2, K6): the list, and one customer with
 * their orders.
 *
 * The list and the access copy only read. The module check is Contao's: only
 * who may open "Kunden" reaches this, and the links lead into "Bestellungen"
 * and "Mitglieder", which check their own rights.
 *
 * The erasure (D-5) writes, and cannot be undone: it asks, besides the
 * module, for the right to EDIT every order it touches — like "Widerruf
 * bearbeiten" and "Erneut senden" (Fable review D-5, S4).
 *
 * Everything else comes from the query string (GET), so a filtered list is
 * a link one can keep: `shop`, `q`, `page`, and `customer` for the detail.
 */
#[Autoconfigure(public: true)]
final class CustomerBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_customers.html.twig';

    public const PER_PAGE = 50;

    public function __construct(
        private readonly CustomerDirectory $directory,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly ContaoFramework $framework,
        private readonly CustomerExport $export,
        private readonly CustomerErasure $erasure,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    public function __invoke(): string
    {
        $this->framework->initialize();

        $query = $this->requests->getCurrentRequest()?->query;
        $shops = $this->directory->shops();
        $shopIds = array_map(static fn (array $s): int => (int) $s['id'], $shops);

        $shopId = (int) $query?->get('shop', 0);
        $shopId = \in_array($shopId, $shopIds, true) ? $shopId : null;
        $search = trim((string) $query?->get('q', ''));
        $key = (string) $query?->get('customer', '');
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');

        $lang = $GLOBALS['TL_LANG']['CONSHO']['customers'] ?? [];
        $base = ['do' => 'consho_customer'];

        if ('' !== $key && null !== $shopId && null !== ($customer = $this->directory->find($shopId, $key))) {
            $detail = $this->router->generate('contao_backend', $base + ['shop' => $shopId, 'customer' => $key]);
            $list = $this->router->generate('contao_backend', $base + ['shop' => $shopId]);

            // D-5 (L1): the copy for an access request. A GET: it changes nothing.
            if ('1' === (string) $query?->get('export', '')) {
                $this->download($shopId, $key);
            }

            // D-5 (L3): the erasure. A POST with Contao's request token, and a
            // tick box — it cannot be undone.
            $request = $this->requests->getCurrentRequest();

            if (null !== $request && $request->isMethod('POST') && 'erase' === $request->request->getString('consho_action')) {
                $this->erase($request->request->getString('consho_confirmed'), $shopId, $key, $lang, $detail, $list);
            }

            return $this->twig->render(self::TEMPLATE, [
                'lang' => $lang,
                'back' => $list,
                'messages' => $this->messages(),
                'exportUrl' => $this->router->generate('contao_backend', $base + ['shop' => $shopId, 'customer' => $key, 'export' => 1]),
                'customer' => $this->row($customer),
                'orders' => array_map(fn (array $o): array => [
                    'number' => $o['orderNumber'],
                    'date' => $this->date($o['createdAt']),
                    'total' => $this->money->format($o['grandTotal'], $o['currency'], $language),
                    'guest' => 0 === $o['member'] || $o['member'] !== $customer->memberId,
                    'erasureRequested' => null === $o['erasureRequestedAt'] ? '' : $this->date($o['erasureRequestedAt']),
                    'url' => $this->router->generate('contao_backend', ['do' => 'consho_order', 'act' => 'edit', 'id' => $o['id']]),
                ], $this->directory->orders($shopId, $key)),
            ]);
        }

        $customers = $this->directory->list($shopId, $search);
        $pages = max(1, (int) ceil(\count($customers) / self::PER_PAGE));
        $page = min($pages, max(1, (int) $query?->get('page', 1)));
        $filter = array_filter(['shop' => $shopId, 'q' => '' !== $search ? $search : null], static fn ($v): bool => null !== $v);

        return $this->twig->render(self::TEMPLATE, [
            'lang' => $lang,
            'messages' => $this->messages(),
            'shops' => array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'title' => (string) $s['title']], $shops),
            'shopId' => $shopId,
            'search' => $search,
            'resetUrl' => $this->router->generate('contao_backend', $base),
            'customers' => array_map(fn (Customer $c): array => $this->row($c) + [
                'url' => $this->router->generate('contao_backend', $base + $filter + ['shop' => $c->shopId, 'customer' => $c->key]),
                'exportUrl' => $this->router->generate('contao_backend', $base + ['shop' => $c->shopId, 'customer' => $c->key, 'export' => 1]),
            ], \array_slice($customers, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            // Contao's "Anzeigen" select: one range per page, the last one
            // ending at the total — the count sits there, as in every list.
            'page' => $page,
            'ranges' => array_map(static fn (int $p): array => [
                'page' => $p,
                'label' => \sprintf('%d - %d', ($p - 1) * self::PER_PAGE + 1, min($p * self::PER_PAGE, \count($customers))),
            ], [] === $customers ? [] : range(1, $pages)),
        ]);
    }

    /**
     * @return never — the ZIP goes out as the response
     */
    private function download(int $shopId, string $key): void
    {
        $export = $this->export->export($shopId, $key, time()) ?? throw new NotFoundHttpException();

        throw new ResponseException(new Response($export['zip'], Response::HTTP_OK, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $export['fileName']),
            // Personal data: never in a shared cache, never kept by the browser.
            'Cache-Control' => 'no-store, private',
        ]));
    }

    /**
     * @param array<string, mixed> $lang
     *
     * @return never — back to the customer, or to the list once they are gone
     */
    private function erase(string $confirmed, int $shopId, string $key, array $lang, string $detail, string $list): void
    {
        $message = $this->framework->getAdapter(Message::class);

        if ('1' !== $confirmed) {
            $message->addError((string) ($lang['eraseUnconfirmed'] ?? 'Please confirm the erasure.'));

            throw new RedirectResponseException($detail);
        }

        foreach ($this->directory->orders($shopId, $key) as $listed) {
            $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$listed['id']]);

            if (false === $order || !$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
                throw new AccessDeniedHttpException();
            }
        }

        $result = $this->erasure->erase($shopId, $key, time()) ?? throw new NotFoundHttpException();

        if ([] !== $result['anonymized']) {
            $message->addConfirmation(\sprintf((string) ($lang['erasedNow'] ?? '%s'), implode(', ', $result['anonymized'])));
        }

        $pending = array_map(
            fn (array $order): string => null === $order['end']
                ? \sprintf((string) ($lang['pendingNoPeriod'] ?? '%s'), $order['number'])
                : \sprintf((string) ($lang['pendingUntil'] ?? '%s (%s)'), $order['number'], $this->date($order['end'])),
            $result['pending'],
        );

        if ([] !== $pending) {
            $message->addInfo(\sprintf((string) ($lang['erasedLater'] ?? '%s'), implode(', ', $pending)));
        }

        if ([] !== $result['running']) {
            $message->addInfo(\sprintf((string) ($lang['erasedRunning'] ?? '%s'), implode(', ', $result['running'])));
        }

        $message->addInfo((string) ($lang['erasedAround'] ?? ''));

        // Everything anonymised: the customer is no longer in the list.
        throw new RedirectResponseException(null === $this->directory->find($shopId, $key) ? $list : $detail);
    }

    private function messages(): string
    {
        return (string) $this->framework->getAdapter(Message::class)->generate();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Customer $customer): array
    {
        return [
            'name' => $customer->name,
            'email' => $customer->email,
            'member' => null !== $customer->memberId,
            'alsoGuest' => $customer->alsoGuest,
            'shop' => $customer->shopTitle,
            'orders' => $customer->orders,
            'last' => null === $customer->lastOrderAt ? '' : $this->date($customer->lastOrderAt),
            'memberUrl' => null === $customer->memberId ? '' : $this->router->generate('contao_backend', ['do' => 'member', 'act' => 'edit', 'id' => $customer->memberId]),
        ];
    }

    /**
     * Like the page "Widerruf bearbeiten" — one date format across Consho's
     * own back end pages.
     */
    private function date(int $timestamp): string
    {
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');

        return (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($timestamp);
    }
}

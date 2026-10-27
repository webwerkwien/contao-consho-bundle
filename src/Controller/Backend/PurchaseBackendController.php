<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Stock\PurchaseLists;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;

/**
 * "Einkauf", its output and "Eingang" (Block G, G-7) — `key` actions of the
 * module consho_stock (config.php).
 *
 * The button "Einkauf" at the list opens a dialog with the articles at their
 * reorder level; entries can be added or taken out. Beside the stock up to
 * two fields, single pieces or packing units, prefilled from the target
 * stock. Copied to the clipboard, printed or exported as CSV, the list is
 * recorded as a purchase list — there it can be output again, and its
 * receipt confirmed in whole or in part.
 *
 * The module right is asked here as well: a `key` action runs after
 * Backend::getBackendModule() checked it, but this is the one place a request
 * of its own would reach without that (the lesson of SubscriptionPause).
 *
 * Downloads and the print view are THROWN as responses — returned, the back
 * end would paste them into its frame (DocumentBackendController).
 */
#[Autoconfigure(public: true)]
final class PurchaseBackendController
{
    public const MODULE = 'consho_stock';

    public function __construct(
        private readonly PurchaseLists $purchases,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function purchase(DataContainer $dc): string
    {
        $this->denyUnlessAllowed();
        $this->loadLanguage();

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_purchase_list'] ?? [];

        if (null !== $request && $request->isMethod('POST')) {
            $post = $request->request;
            $quantities = self::quantities($post->all('take'), $post->all('pieces'), $post->all('packs'), $this->purchases->candidates());

            if (null === $quantities) {
                $this->message()->addError((string) ($lang['invalidQuantity'] ?? 'Please enter whole numbers.'));

                throw new RedirectResponseException($request->getUri());
            }

            $listId = $this->purchases->create($quantities, $this->userId(), time());

            if (0 === $listId) {
                $this->message()->addError((string) ($lang['nothingToOrder'] ?? 'Nothing was chosen.'));

                throw new RedirectResponseException($request->getUri());
            }

            // Saved — now to the list's own page, never the same form again
            // (G-7). The clipboard page is the output itself.
            $format = $post->getString('format');
            $target = $this->router->generate('contao_backend', array_filter([
                'do' => self::MODULE,
                'table' => 'tl_consho_purchase_list',
                'key' => 'purchaseOutput',
                'id' => $listId,
                'popup' => $request->query->get('popup'),
                'saved' => 1,
                'format' => 'clipboard' === $format ? 'clipboard' : null,
                'then' => \in_array($format, ['csv', 'print'], true) ? $format : null,
            ], static fn (mixed $value): bool => null !== $value && '' !== $value));

            throw new RedirectResponseException($target);
        }

        return $this->twig->render('@Contao/backend/consho_purchase.html.twig', [
            'messages' => (string) $this->message()->generate(),
            'action' => null === $request ? '' : $request->getUri(),
            'lang' => $lang,
            'lines' => array_map(static fn (array $line): array => [
                ...$line,
                'stock' => self::plain($line['stock']),
                'ordered' => self::plain($line['ordered']),
                'warning' => self::plain($line['warning']),
                'target' => self::plain($line['target']),
                'pieces' => null === $line['proposed'] ? '' : self::plain($line['proposed']),
                'packs' => null === $line['proposed'] || $line['packUnit'] < 2 ? '' : (string) BigDecimal::of($line['proposed'])->dividedBy($line['packUnit'], 0),
            ], $this->purchases->candidates()),
        ]);
    }

    /**
     * Hands out a saved list again — as it was saved, never recomputed.
     */
    public function output(DataContainer $dc): string
    {
        $this->denyUnlessAllowed();
        $this->loadLanguage();

        $listId = (int) $dc->id;
        $list = $this->list($listId);
        $request = $this->requests->getCurrentRequest();
        $format = null === $request ? '' : $request->query->getString('format');

        if ('' !== $format) {
            $this->respond($listId, $format);
        }

        // The page's own address without the one-time parts.
        $url = null === $request ? '' : $this->router->generate('contao_backend', array_filter([
            'do' => self::MODULE,
            'table' => 'tl_consho_purchase_list',
            'key' => 'purchaseOutput',
            'id' => $listId,
            'popup' => $request->query->get('popup'),
        ], static fn (mixed $value): bool => null !== $value && '' !== $value));

        return $this->twig->render('@Contao/backend/consho_purchase_output.html.twig', [
            'lang' => $GLOBALS['TL_LANG']['tl_consho_purchase_list'] ?? [],
            'list' => $list,
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'url' => $url,
            'saved' => null !== $request && $request->query->has('saved'),
            'then' => null === $request ? '' : $request->query->getString('then'),
        ]);
    }

    /**
     * "Eingang": what came, per line, and "nicht lieferbar" for the rest (E3).
     */
    public function receive(DataContainer $dc): string
    {
        $this->denyUnlessAllowed();
        $this->loadLanguage();

        $listId = (int) $dc->id;
        $list = $this->list($listId);
        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_purchase_list'] ?? [];

        if (null !== $request && $request->isMethod('POST')) {
            $post = $request->request;
            $received = [];

            foreach ($post->all('received') as $itemId => $value) {
                if (\is_scalar($value)) {
                    $received[(int) $itemId] = (string) $value;
                }
            }

            $unavailable = array_fill_keys(array_map('intval', array_keys($post->all('unavailable'))), true);
            $error = $this->purchases->receive($listId, $received, $unavailable, $this->userId(), time());

            null === $error
                ? $this->message()->addConfirmation(\sprintf((string) ($lang['received'] ?? 'Receipt booked for %s.'), (string) $list['number']))
                : $this->message()->addError((string) ($lang[$error] ?? $error));

            throw new RedirectResponseException($request->getUri());
        }

        return $this->twig->render('@Contao/backend/consho_purchase_receive.html.twig', [
            'messages' => (string) $this->message()->generate(),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
            'itemStates' => $GLOBALS['TL_LANG']['tl_consho_purchase_item']['states'] ?? [],
            'list' => $list,
            'items' => array_map(static fn (array $item): array => [
                'id' => (int) $item['id'],
                'title' => (string) $item['title'],
                'sku' => (string) $item['sku'],
                'supplier' => (string) $item['supplier'],
                'quantity' => self::plain($item['quantity']),
                'received' => self::plain($item['received']),
                'rest' => self::plain((string) BigDecimal::of(StockBooking::scaled($item['quantity']))->minus(BigDecimal::of(StockBooking::scaled($item['received'])))),
                'state' => (string) $item['state'],
                'done' => \in_array($item['state'], ['received', 'unavailable'], true),
            ], $this->purchases->items($listId)),
        ]);
    }

    /**
     * The chosen lines in pieces — a pack count times the pack size where the
     * article has packs (E2), pieces otherwise. Only ticked lines.
     *
     * @param array<mixed> $take
     * @param array<mixed> $pieces
     * @param array<mixed> $packs
     * @param list<array{article: int, packUnit: int}> $candidates
     *
     * @return array<int, string>|null NULL when an entry is no number
     *
     * @internal public for the test only
     */
    public static function quantities(array $take, array $pieces, array $packs, array $candidates): array|null
    {
        $units = array_column($candidates, 'packUnit', 'article');
        $quantities = [];

        foreach (array_keys($take) as $article) {
            $article = (int) $article;

            if (!isset($units[$article])) {
                continue;
            }

            $packed = $units[$article] > 1;
            $value = trim((string) (($packed ? $packs[$article] ?? '' : $pieces[$article] ?? '') ?: '0'));

            try {
                $amount = BigDecimal::of(str_replace(',', '.', $value));
            } catch (MathException) {
                return null;
            }

            // Packs are whole (E2); pieces may be decimal (kg, m).
            if ($amount->isNegative() || ($packed && !$amount->isEqualTo($amount->toScale(0, RoundingMode::Down)))) {
                return null;
            }

            $quantities[$article] = (string) ($packed ? $amount->multipliedBy($units[$article]) : $amount)->toScale(4);
        }

        return $quantities;
    }

    /**
     * The list as text: one line per article, tab separated, a header first —
     * for the clipboard, and the same columns for CSV and print (L4: what a
     * process outside Consho needs to order without guessing).
     *
     * @param list<array<string, mixed>> $items
     *
     * @param array<string, mixed>       $lang
     *
     * @return list<list<string>>
     *
     * @internal public for the test only
     */
    public static function table(array $items, array $lang): array
    {
        $rows = [[
            (string) ($lang['colSupplier'] ?? 'Lieferant'),
            (string) ($lang['colSupplierSku'] ?? 'Bestellnummer Lieferant'),
            (string) ($lang['colSku'] ?? 'Artikelnummer'),
            (string) ($lang['colArticle'] ?? 'Artikel'),
            (string) ($lang['colPieces'] ?? 'Stück'),
            (string) ($lang['colPackUnit'] ?? 'VPE'),
            (string) ($lang['colPacks'] ?? 'Packungen'),
        ]];

        foreach ($items as $item) {
            $pieces = BigDecimal::of(StockBooking::scaled($item['quantity']));
            $unit = max(1, (int) $item['packUnit']);

            $rows[] = [
                (string) $item['supplier'],
                (string) $item['supplierSku'],
                (string) $item['sku'],
                (string) $item['title'],
                (string) $pieces->strippedOfTrailingZeros(),
                $unit > 1 ? (string) $unit : '',
                $unit > 1 ? (string) $pieces->dividedBy($unit, 0, RoundingMode::Up) : '',
            ];
        }

        return $rows;
    }

    private function respond(int $listId, string $format): never
    {
        $list = $this->list($listId);
        $lang = $GLOBALS['TL_LANG']['tl_consho_purchase_list'] ?? [];
        $rows = self::table($this->purchases->items($listId), $lang);
        $fileName = strtolower((string) $list['number']);

        if ('csv' === $format) {
            $handle = fopen('php://temp', 'r+');
            \assert(false !== $handle);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::csvCell(...), $row), ';', '"', '');
            }

            rewind($handle);
            // With a byte order mark: Excel opens UTF-8 only with it.
            $csv = "\u{FEFF}".stream_get_contents($handle);

            throw new ResponseException(new Response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName.'.csv'),
                'Cache-Control' => 'private, no-store',
            ]));
        }

        $page = 'print' === $format ? '@Contao/backend/consho_purchase_print.html.twig' : '@Contao/backend/consho_purchase_clipboard.html.twig';

        throw new ResponseException(new Response($this->twig->render($page, [
            'lang' => $lang,
            'list' => $list,
            'rows' => $rows,
            'text' => implode("\n", array_map(static fn (array $row): string => implode("\t", $row), $rows)),
            'lists' => $this->router->generate('contao_backend', ['do' => self::MODULE, 'table' => 'tl_consho_purchase_list']),
        ]), 200, ['Cache-Control' => 'private, no-store']));
    }

    /**
     * @return array<string, mixed>
     */
    private function list(int $listId): array
    {
        $list = $this->connection->fetchAssociative('SELECT * FROM tl_consho_purchase_list WHERE id = ?', [$listId]);

        return false === $list ? throw new NotFoundHttpException() : $list;
    }

    private function denyUnlessAllowed(): void
    {
        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, self::MODULE)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function userId(): int
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (int) $user->id : 0;
    }

    /**
     * @return Adapter<Message>
     */
    private function message(): Adapter
    {
        return $this->framework->getAdapter(Message::class);
    }

    /**
     * A cell Excel would read as a formula gets a leading apostrophe
     * (G-7): titles and supplier names are typed in the back end.
     *
     * @internal public for the test only
     */
    public static function csvCell(string $value): string
    {
        return '' !== $value && \in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function loadLanguage(): void
    {
        // The module's table is tl_consho_stock; the texts of these pages live
        // in the purchase tables' files (G-7).
        $system = $this->framework->getAdapter(System::class);
        $system->loadLanguageFile('tl_consho_purchase_list');
        $system->loadLanguageFile('tl_consho_purchase_item');
    }

    private static function plain(string $value): string
    {
        return (string) BigDecimal::of(StockBooking::scaled($value))->strippedOfTrailingZeros();
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Stock\ArticleAvailability;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\PurchaseLists;
use Webwerkwien\ContaoConshoBundle\Stock\StockBooking;

/**
 * The page "Lagerstand" (Block G, G16, G-7): one row per article with limited
 * stock — what is there, what is on order (E1), the state the customer sees,
 * "wieder lieferbar ab", how many members wait for it, the supplier.
 *
 * "Einkauf" opens in Contao's modal ; the link works without it too.
 */
final class StockListListener
{
    /**
     * @var array<int, array{product: array<string, mixed>|false, state: ArticleAvailability, ordered: BigDecimal|null, notices: int}>
     */
    private array $columns = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Availability $availability,
        private readonly PurchaseLists $purchases,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, mixed>    $args
     *
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_stock', target: 'list.label.label')]
    public function label(array $row, string $label, DataContainer $dc, array $args): array
    {
        $article = (int) $row['article'];

        if (!isset($this->columns[$article])) {
            $this->prefetch($article);
        }

        ['product' => $product, 'state' => $state, 'ordered' => $ordered, 'notices' => $notices] = $this->columns[$article];
        $lang = $GLOBALS['TL_LANG']['tl_consho_stock'] ?? [];
        $keys = 'keys' === $row['source'];
        $quantity = (string) BigDecimal::of(StockBooking::scaled($keys ? $state->available : $row['quantity']))->strippedOfTrailingZeros();
        $ordered = $keys ? null : $ordered;

        return [
            false === $product ? '#'.$article : StringUtil::specialchars(PurchaseLists::title((string) $product['mainTitle'], (int) $product['pid'] > 0 ? (string) $product['title'] : '')),
            false === $product ? '' : StringUtil::specialchars((string) $product['sku']),
            $keys ? \sprintf((string) ($lang['keysFree'] ?? '%s free'), $quantity) : $quantity,
            null === $ordered || $ordered->isZero() ? '' : (string) $ordered->strippedOfTrailingZeros(),
            (string) ($GLOBALS['TL_LANG']['CONSHO']['stock'][$state->state->value] ?? $state->state->value),
            (string) ($args[5] ?? ''),
            $notices > 0 ? (string) $notices : '',
            StringUtil::specialchars((string) $row['supplier']),
        ];
    }

    /**
     * The columns of every active row at once, on the first label of the
     * page (Nachlese K4, G-7): four queries per row made a page of
     * a few hundred articles noticeably slow. The article asked for is added
     * in case the list shows one that is no longer active.
     */
    private function prefetch(int $article): void
    {
        $articles = array_values(array_unique([
            $article,
            ...array_map('intval', $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_stock WHERE active = 1')),
        ]));

        $products = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT a.id, a.title, a.pid, a.sku, m.title AS mainTitle
               FROM tl_consho_product a
               JOIN tl_consho_product m ON m.id = CASE WHEN a.pid > 0 THEN a.pid ELSE a.id END
              WHERE a.id IN (?)',
            [$articles],
            [ArrayParameterType::INTEGER],
        ) as $product) {
            $products[(int) $product['id']] = $product;
        }

        $states = $this->availability->of($articles);
        $ordered = $this->purchases->ordered($articles);
        $notices = array_map('intval', $this->connection->fetchAllKeyValue(
            'SELECT article, COUNT(*) FROM tl_consho_stock_notice WHERE article IN (?) GROUP BY article',
            [$articles],
            [ArrayParameterType::INTEGER],
        ));

        foreach ($articles as $id) {
            $this->columns[$id] = [
                'product' => $products[$id] ?? false,
                'state' => $states[$id] ?? ArticleAvailability::unlimited(),
                'ordered' => $ordered[$id] ?? null,
                'notices' => $notices[$id] ?? 0,
            ];
        }
    }

    /**
     * To the article's mask, where stock is booked by hand (G3).
     */
    #[AsCallback(table: 'tl_consho_stock', target: 'list.operations.product.button')]
    public function productButton(DataContainerOperation $operation): void
    {
        $operation->setUrl($this->router->generate('contao_backend', [
            'do' => 'consho_product',
            'act' => 'edit',
            'id' => (int) ($operation->getRecord()['article'] ?? 0),
        ]));
    }

    /**
     * "Einkauf" in Contao's modal, with the popup layout.
     */
    #[AsCallback(table: 'tl_consho_stock', target: 'list.global_operations.purchase.button')]
    public function purchaseButton(DataContainerOperation $operation): void
    {
        $title = (string) ($GLOBALS['TL_LANG']['tl_consho_stock']['purchase'][0] ?? 'Einkauf');

        $operation['attributes']->set(
            'onclick',
            // HtmlAttributes escapes the attribute itself; only the quote of
            // the JavaScript string is ours (G-7).
            \sprintf("Backend.openModalIframe({'title':%s,'url':this.href+'&popup=1'});return false", json_encode($title, \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE)),
        );
    }
}

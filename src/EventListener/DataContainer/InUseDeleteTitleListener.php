<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogReferences;

/**
 * Says WHY a delete button is grey — even for an administrator. The delete
 * voters refuse a record that is still in use (CatalogReferences: shop, order
 * status, mail configuration, product type, manufacturer, property, value,
 * subscription rule, tax class, shipping and payment method); Contao only
 * greys the icon, which reads like a missing permission.
 *
 * Runs after DefaultOperationsListener (priority 200), which puts the
 * voter's answer into the delete operation's button_callback, and wraps it.
 * The sentence is set only when the button is disabled AND the record is
 * actually in use — greyed for a missing permission, it would be untrue.
 * The two methods keep their own sentence: what holds them is always an
 * order.
 *
 * One indexed query per row of a short list; the voter asks the same.
 */
#[AsHook('loadDataContainer', priority: 100)]
final class InUseDeleteTitleListener
{
    private const METHODS = ['tl_consho_shipping_method', 'tl_consho_payment_method'];

    public function __construct(private readonly CatalogReferences $references)
    {
    }

    public function __invoke(string $table): void
    {
        if (!CatalogReferences::covers($table) || !isset($GLOBALS['TL_DCA'][$table]['list']['operations']['delete'])) {
            return;
        }

        $inner = $GLOBALS['TL_DCA'][$table]['list']['operations']['delete']['button_callback'] ?? null;
        $references = $this->references;
        $key = \in_array($table, self::METHODS, true) ? 'methodInUse' : 'recordInUse';

        $GLOBALS['TL_DCA'][$table]['list']['operations']['delete']['button_callback'] = static function (DataContainerOperation $operation) use ($inner, $table, $references, $key): void {
            if ($inner instanceof \Closure) {
                $inner($operation);
            }

            // Still a link: nothing refused, nothing to explain.
            if (isset($operation['href']) || isset($operation['route'])) {
                return;
            }

            $id = (int) ($operation->getRecord()['id'] ?? 0);

            if ($references->isInUse($table, $id)) {
                $text = $GLOBALS['TL_LANG']['CONSHO'][$key] ?? $operation['title'];

                /*
                 * `title` lands in the icon's ALT (operations.html.twig, the
                 * disabled branch has no link around it). Contao's tooltip
                 * shows the alt of an image, but only targets `img[alt]`
                 * inside a link or button — so the icon is made a target
                 * directly. A title attribute would do that too, but Contao
                 * leaves it on an image and the browser adds its own tooltip.
                 */
                $operation['title'] = $text;
                $operation['iconAttributes']->set('data-contao--tooltips-target', 'tooltip');
            }
        };
    }
}

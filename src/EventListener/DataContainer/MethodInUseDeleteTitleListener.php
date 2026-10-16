<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogReferences;

/**
 * Says WHY the delete button of a shipping or payment method is grey
 * (Michael, 2026-09-25, on "Paket": „der Lösch-Button grau, obwohl ich als
 * Admin drinnen bin"). The method's delete voter refuses the delete of a
 * method an order references (CatalogReferences); Contao only greys the
 * icon, which reads like a missing permission.
 *
 * Runs after DefaultOperationsListener (priority 200), which puts the
 * voter's answer into the delete operation's button_callback, and wraps it.
 * The sentence is set only when the button is disabled AND the method is
 * actually referenced — greyed for a missing permission, it would be untrue.
 *
 * One indexed query per row of a short list; the voter asks the same.
 */
#[AsHook('loadDataContainer', priority: 100)]
final class MethodInUseDeleteTitleListener
{
    private const TABLES = ['tl_consho_shipping_method', 'tl_consho_payment_method'];

    public function __construct(private readonly CatalogReferences $references)
    {
    }

    public function __invoke(string $table): void
    {
        if (!\in_array($table, self::TABLES, true) || !isset($GLOBALS['TL_DCA'][$table]['list']['operations']['delete'])) {
            return;
        }

        $inner = $GLOBALS['TL_DCA'][$table]['list']['operations']['delete']['button_callback'] ?? null;
        $references = $this->references;

        $GLOBALS['TL_DCA'][$table]['list']['operations']['delete']['button_callback'] = static function (DataContainerOperation $operation) use ($inner, $table, $references): void {
            if ($inner instanceof \Closure) {
                $inner($operation);
            }

            // Still a link: nothing refused, nothing to explain.
            if (isset($operation['href']) || isset($operation['route'])) {
                return;
            }

            $id = (int) ($operation->getRecord()['id'] ?? 0);

            if ($references->isInUse($table, $id)) {
                $text = $GLOBALS['TL_LANG']['CONSHO']['methodInUse'] ?? $operation['title'];

                /*
                 * `title` alone lands in the icon's ALT (operations.html.twig,
                 * the disabled branch has no link around it) — and a browser
                 * shows no alt on hover. Seen on c5 on 2026-09-25. The icon
                 * itself carries the title.
                 */
                $operation['title'] = $text;
                $operation['iconAttributes']->set('title', $text);
            }
        };
    }
}

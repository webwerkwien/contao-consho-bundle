<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalPinRepository;

/**
 * The products a pin can point at: main products only, as "Title (SKU)".
 */
final class PinnedProductOptionsListener
{
    public function __construct(private readonly DbalPinRepository $pins)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_content', target: 'fields.conshoProduct.options')]
    public function __invoke(): array
    {
        return $this->pins->mainProductOptions();
    }
}

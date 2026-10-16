<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Twig\Finder\FinderFactory;
use Contao\DataContainer;

/**
 * The partials a product type may bring to the detail page.
 *
 * Every variant of the identifier below, found the way Contao's own
 * TemplateOptionsListener finds content element variants — through the Finder,
 * not Controller::getTemplateGroup(), which refuses identifiers with a slash in
 * 5.7 and names this service instead (Controller.php:94-97).
 *
 * Limit, stated where it is felt: the Finder looks at one theme at a time, so a
 * partial that exists only in a theme is not offered.
 *
 * Not unit tested: Finder is final with an internal constructor. Checked in the
 * acceptance on the test server.
 */
class DetailPartialOptionsListener
{
    /**
     * A partial lives at templates/consho_product_type/<name>.html.twig.
     */
    public const IDENTIFIER = 'consho_product_type';

    public function __construct(private readonly FinderFactory $finders)
    {
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_product_type', target: 'fields.detailPartial.options')]
    public function __invoke(DataContainer|null $dc = null): array
    {
        return $this->finders->create()
            ->identifier(self::IDENTIFIER)
            ->extension('html.twig')
            ->withVariants(true)
            ->asTemplateOptions(false)
        ;
    }
}

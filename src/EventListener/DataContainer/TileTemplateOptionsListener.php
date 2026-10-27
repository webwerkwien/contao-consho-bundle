<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Twig\Finder\FinderFactory;
use Contao\DataContainer;

/**
 * The tile templates a listing element may use instead of the bundled one.
 *
 * Every variant of the identifier below, found the way Contao's own
 * TemplateOptionsListener finds content element variants — through the Finder.
 * The field used Controller::getTemplateGroup() before, which refuses
 * identifiers with a slash in 5.7 (Controller.php:94-97). With it,
 * the edit mask of every listing element died with
 * an Internal Server Error.
 *
 * Partials are left out, as the core does: a template starting with an
 * underscore is a building block, not a tile.
 *
 * Limit, stated where it is felt: the Finder looks at one theme at a time, so a
 * tile that exists only in a theme is not offered.
 *
 * Not unit tested: Finder is final with an internal constructor. Checked by hand
 * in a running installation.
 */
class TileTemplateOptionsListener
{
    /**
     * A tile lives at templates/content_element/consho_product_tile/<name>.html.twig.
     */
    public const IDENTIFIER = 'content_element/consho_product_tile';

    public function __construct(private readonly FinderFactory $finders)
    {
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_content', target: 'fields.conshoTileTemplate.options')]
    public function __invoke(DataContainer|null $dc = null): array
    {
        return $this->finders->create()
            ->identifier(self::IDENTIFIER)
            ->extension('html.twig')
            ->withVariants(true)
            ->excludePartials()
            ->asTemplateOptions(false)
        ;
    }
}

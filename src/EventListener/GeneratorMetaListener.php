<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Event\LayoutEvent;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\PageModel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * `<meta name="generator" content="Consho">` on shop pages (D-6, G1).
 *
 * A hint against fake shops — with the generator hint, and
 * deliberately no tool for cloning shops en masse: whoever investigates a shop can see
 * what built it. Without a version — that would help an attacker pick an
 * exploit more than it helps a researcher.
 *
 * A second tag next to Contao's own is valid HTML: the value "identifies one
 * of the software packages used to generate the document" (WHATWG HTML,
 * "Standard metadata names", generator); the spec limits the count only for
 * charset, application-name and color-scheme.
 *
 * Only below a root page that has a shop: a multi-tenant installation
 * carries sites that have nothing to do with Consho.
 *
 * Through Contao's HtmlHeadBag, which both page kinds print: the legacy
 * fe_page reads it after the generatePage hook (PageRegular), the modern
 * layout after LayoutEvent (the head is deferred).
 */
final class GeneratorMetaListener
{
    public const NAME = 'Consho';

    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ResponseContextAccessor $contexts,
    ) {
    }

    #[AsHook('generatePage')]
    public function onGeneratePage(PageModel $page): void
    {
        $this->apply($page);
    }

    #[AsEventListener]
    public function onLayout(LayoutEvent $event): void
    {
        $this->apply($event->getPage());
    }

    private function apply(PageModel $page): void
    {
        $root = $this->shops->rootOf($page);

        if (null === $root || $this->shops->shopIdOfPage($root) < 1) {
            return;
        }

        $context = $this->contexts->getResponseContext();

        if (null === $context || !$context->has(HtmlHeadBag::class)) {
            return;
        }

        $head = $context->get(HtmlHeadBag::class);

        // Once, whichever of the two paths runs, and however often.
        foreach ($head->getMetaTags() as $tag) {
            if ('generator' === ($tag['name'] ?? null) && self::NAME === ($tag['content'] ?? null)) {
                return;
            }
        }

        $head->addMetaTag(new HtmlAttributes(['name' => 'generator', 'content' => self::NAME]));
    }
}

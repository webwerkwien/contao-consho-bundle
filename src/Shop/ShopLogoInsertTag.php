<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shop;

use Contao\CoreBundle\DependencyInjection\Attribute\AsInsertTag;
use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Image\Studio\Figure;
use Contao\CoreBundle\Image\Studio\FigureRenderer;
use Contao\CoreBundle\InsertTag\InsertTagResult;
use Contao\CoreBundle\InsertTag\OutputType;
use Contao\CoreBundle\InsertTag\ResolvedInsertTag;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\StringUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;

/**
 * The shop's logo in the theme (GARAN Teil 2, L6),
 * as a figure like Contao's own.
 *
 *   {{consho_logo}}                     <figure> with the image as uploaded
 *   {{consho_logo::<size>}}             with an image size of the theme (id or `_name`)
 *   {{consho_logo::<size>::<template>}} and a figure template of one's own (.html.twig, Template Studio)
 *   {{consho_logo::url}}                the path of the image only
 *
 * The same file as on the invoice and in the mails (L1) — one logo, not a
 * second field that drifts. Rendered by Contao's FigureRenderer, as
 * {{figure::…}} does: responsive sizes, the Template Studio, the file's own
 * metadata. The company is the alternative text only where the file manager
 * gives none.
 *
 * Positional rather than `?size=…&template=…` like {{figure}}: there the query
 * string sits in the parameter after the file, and this tag has no file to
 * name. An insert tag's name takes no `?` (InsertTagParser).
 *
 * Empty — never a broken image — outside a shop, without a logo, for a file
 * that is gone, an original in a folder that is not public, or a template
 * that does not exist.
 */
final class ShopLogoInsertTag
{
    private const DEFAULT_TEMPLATE = '@ContaoCore/Image/Studio/figure.html.twig';

    public function __construct(
        private readonly ShopResolver $shops,
        #[Autowire(service: 'contao.routing.page_finder')]
        private readonly PageFinder $pages,
        #[Autowire(service: 'contao.image.studio.figure_renderer')]
        private readonly FigureRenderer $figures,
        private readonly Environment $twig,
        // Optional for the tests that do not ask about the folder.
        private readonly PublicFiles|null $files = null,
    ) {
    }

    #[AsInsertTag('consho_logo')]
    public function __invoke(ResolvedInsertTag $insertTag): InsertTagResult
    {
        $page = $this->pages->getCurrentPage();
        $shop = null === $page ? null : $this->shops->resolveForPage($page);
        $uuid = null === $shop ? null : $shop->invoiceLogo;

        if (null === $shop || !\is_string($uuid) || '' === $uuid) {
            return new InsertTagResult('');
        }

        // Changing the logo at the shop invalidates every page that shows it.
        $tags = ['contao.db.tl_consho_shop.'.$shop->id];
        $first = (string) ($insertTag->getParameters()->get(0) ?? '');
        $url = 'url' === $first;
        $size = $url || '' === $first ? null : (ctype_digit($first) ? (int) $first : $first);

        // Without a size the image is the original under files/ — from a folder
        // that is not public that is a 403, a broken image (L6). A
        // size has Contao write a copy to assets/images, which is public.
        if (null === $size && null !== $this->files && !$this->files->isPublic($uuid)) {
            return new InsertTagResult('', OutputType::html, null, $tags);
        }

        try {
            $figure = $this->figure(StringUtil::binToUuid($uuid), $size, trim((string) $shop->invoiceCompany));

            if (null === $figure) {
                return new InsertTagResult('', OutputType::html, null, $tags);
            }

            if ($url) {
                return new InsertTagResult($figure->getImage()->getImageSrc(), OutputType::url, null, $tags);
            }

            // Inside the try, as {{figure}} has it: a template that does not
            // exist must leave the tag empty, not the page a 500 (L6).
            $html = $this->twig->render(self::template((string) ($insertTag->getParameters()->get(1) ?? '')), ['figure' => $figure]);
        } catch (\Throwable) {
            // As {{figure}}: a size, file or template that fails shows nothing.
            return new InsertTagResult('', OutputType::html, null, $tags);
        }

        return new InsertTagResult($html, OutputType::html, null, $tags);
    }

    /**
     * A figure template of the Template Studio, by name — `my_logo.html.twig`
     * is `@Contao/my_logo.html.twig`. Nothing else from an editor's text field:
     * no other namespace, no path tricks (L6). The image studio's own
     * figure templates stay allowed.
     */
    private static function template(string $name): string
    {
        if ('' === $name || str_contains($name, '..') || 1 !== preg_match('/^(@Contao\/|@ContaoCore\/Image\/Studio\/)?[\w\/-]+\.html\.twig$/', $name)) {
            return self::DEFAULT_TEMPLATE;
        }

        return str_starts_with($name, '@') ? $name : '@Contao/'.$name;
    }

    /**
     * The file's own alternative text first; the company where it has none.
     */
    private function figure(string $uuid, int|string|null $size, string $company): Figure|null
    {
        $figure = $this->figures->buildFigure($uuid, $size);

        if (null === $figure || '' === $company || '' !== ($figure->getMetadata()?->getAlt() ?? '')) {
            return $figure;
        }

        return $this->figures->buildFigure($uuid, $size, ['overwriteMetadata' => new Metadata([Metadata::VALUE_ALT => $company])]);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shop;

use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Figure;
use Contao\CoreBundle\Image\Studio\FigureRenderer;
use Contao\CoreBundle\Image\Studio\ImageResult;
use Contao\CoreBundle\InsertTag\OutputType;
use Contao\CoreBundle\InsertTag\ResolvedInsertTag;
use Contao\CoreBundle\InsertTag\ResolvedParameters;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\PageModel;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Shop\ShopLogoInsertTag;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * L6 (Michael, 2026-10-10: „L6a", als <figure> wie Contao): {{consho_logo}}
 * gibt das Logo des Shops der aktuellen Seite über Contaos FigureRenderer aus.
 */
class ShopLogoInsertTagTest extends TestCase
{
    private const UUID = '9e474bae-ce18-11ec-9465-cadae3e5cf5d';

    /** @var list<array{0: string, 1: int|string|null, 2: array<string, mixed>}> */
    private array $built = [];

    public function testFigureMitDemDefaultTemplateUndCacheTag(): void
    {
        $result = $this->tag(alt: 'Logo laut Dateiverwaltung')->__invoke($this->insertTag());

        self::assertSame('default:/files/logo.png:Logo laut Dateiverwaltung', $result->getValue());
        self::assertSame(OutputType::html, $result->getOutputType());
        self::assertSame(['contao.db.tl_consho_shop.7'], $result->getCacheTags());
        self::assertSame([[self::UUID, null, []]], $this->built, 'die Datei des Shops, ohne Größe, Metadaten aus der Datei');
    }

    public function testOhneAlternativtextDerDateiStehtDieFirma(): void
    {
        $result = $this->tag(alt: '')->__invoke($this->insertTag());

        self::assertSame('default:/files/logo.png:Web Werk GmbH', $result->getValue());
        self::assertCount(2, $this->built);
        self::assertEquals(['overwriteMetadata' => new Metadata([Metadata::VALUE_ALT => 'Web Werk GmbH'])], $this->built[1][2]);
    }

    public function testGroesseUndEigenesTemplate(): void
    {
        $byId = $this->tag()->__invoke($this->insertTag('5', 'my_logo.html.twig'));
        $byName = $this->tag()->__invoke($this->insertTag('_header'));

        self::assertSame('own:/files/logo.png', $byId->getValue());
        self::assertSame(5, $this->built[0][1], 'eine Ziffernfolge ist die Id der Bildgröße');
        self::assertStringStartsWith('default:', $byName->getValue());
        self::assertSame('_header', $this->built[1][1]);
    }

    public function testEinTemplatePfadMitPunktPunktFaelltAufsDefault(): void
    {
        self::assertStringStartsWith('default:', $this->tag()->__invoke($this->insertTag('', '../../secret.html.twig'))->getValue());
        self::assertStringStartsWith('default:', $this->tag()->__invoke($this->insertTag('', 'mod_navigation'))->getValue());
    }

    /**
     * Review L6, 2: aus einem Redaktionsfeld nur Templates des Template
     * Studios (`@Contao/…`) und die Figure-Templates des Image Studios —
     * kein fremder Namespace.
     */
    public function testNurTemplatesDesTemplateStudios(): void
    {
        self::assertStringStartsWith('default:', $this->tag()->__invoke($this->insertTag('', '@Other/x.html.twig'))->getValue());
        self::assertSame('own:/files/logo.png', $this->tag()->__invoke($this->insertTag('', '@Contao/my_logo.html.twig'))->getValue());
    }

    /**
     * Review L6, 1: ein Template, das es nicht gibt, lässt den Tag leer —
     * nicht die Seite mit 500 stehen.
     */
    public function testEinFehlendesTemplateLaesstDenTagLeer(): void
    {
        self::assertSame('', $this->tag()->__invoke($this->insertTag('', 'vertippt.html.twig'))->getValue());
    }

    /**
     * Review L6, 5: das Original aus einem nicht öffentlichen Ordner wäre ein
     * 403 — leer. Mit Bildgröße schreibt Contao eine Kopie nach assets/images.
     */
    public function testOriginalAusGeschuetztemOrdnerNurMitBildgroesse(): void
    {
        $files = $this->createMock(PublicFiles::class);
        $files->method('isPublic')->willReturn(false);

        self::assertSame('', $this->tag(files: $files)->__invoke($this->insertTag())->getValue(), 'Original');
        self::assertSame('', $this->tag(files: $files)->__invoke($this->insertTag('url'))->getValue(), 'Pfad des Originals');
        self::assertStringStartsWith('default:', $this->tag(files: $files)->__invoke($this->insertTag('5'))->getValue(), 'mit Größe');
        self::assertSame([[self::UUID, 5, []]], $this->built, 'ohne Größe nicht einmal gebaut');
    }

    public function testUrlGibtNurDenPfad(): void
    {
        $result = $this->tag()->__invoke($this->insertTag('url'));

        self::assertSame('/files/logo.png', $result->getValue());
        self::assertSame(OutputType::url, $result->getOutputType());
        self::assertNull($this->built[0][1]);
    }

    /**
     * Leer, nie ein kaputtes Bild: keine Seite, kein Shop, kein Logo, eine
     * Datei, die es nicht mehr gibt, eine Größe, die das Image Studio ablehnt.
     */
    public function testLeerStattKaputt(): void
    {
        self::assertSame('', $this->tag(page: false)->__invoke($this->insertTag())->getValue(), 'keine Seite');
        self::assertSame('', $this->tag(shop: false)->__invoke($this->insertTag())->getValue(), 'kein Shop');
        self::assertSame('', $this->tag(logo: null)->__invoke($this->insertTag())->getValue(), 'kein Logo');
        self::assertSame('', $this->tag(figure: false)->__invoke($this->insertTag())->getValue(), 'Datei weg');
        self::assertSame('', $this->tag(throws: true)->__invoke($this->insertTag('_kaputt'))->getValue(), 'Größe abgelehnt');
    }

    private function insertTag(string ...$parameters): ResolvedInsertTag
    {
        return new ResolvedInsertTag('consho_logo', new ResolvedParameters(array_values($parameters)), []);
    }

    private function tag(bool $page = true, bool $shop = true, string|null $logo = self::UUID, string $alt = 'Logo', bool $figure = true, bool $throws = false, PublicFiles|null $files = null): ShopLogoInsertTag
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnMap([['type', 'root'], ['id', 3]]);
        $root->method('row')->willReturn(['id' => 3, 'consho_shop' => $shop ? 7 : 0]);

        $model = $this->createMock(ShopModel::class);
        $model->method('__get')->willReturnMap([
            ['id', 7],
            ['invoiceLogo', null === $logo ? null : StringUtil::uuidToBin($logo)],
            ['invoiceCompany', ' Web Werk GmbH '],
        ]);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturn($model);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([[ShopModel::class, $shopAdapter]]);

        $pages = $this->createMock(PageFinder::class);
        $pages->method('getCurrentPage')->willReturn($page ? $root : null);

        $figures = $this->createMock(FigureRenderer::class);
        $figures->method('buildFigure')->willReturnCallback(function (string $from, int|string|null $size, array $configuration = []) use ($alt, $figure, $throws): Figure|null {
            $this->built[] = [$from, $size, $configuration];

            if ($throws) {
                throw new \InvalidArgumentException('unknown size');
            }

            if (!$figure) {
                return null;
            }

            $image = $this->createMock(ImageResult::class);
            $image->method('getImageSrc')->willReturn('/files/logo.png');

            return new Figure($image, $configuration['overwriteMetadata'] ?? new Metadata([Metadata::VALUE_ALT => $alt]));
        });

        $twig = new Environment(new ArrayLoader([
            '@ContaoCore/Image/Studio/figure.html.twig' => 'default:{{ figure.image.imageSrc }}:{{ figure.metadata.alt }}',
            '@Contao/my_logo.html.twig' => 'own:{{ figure.image.imageSrc }}',
            '@Contao/backend/secret.html.twig' => 'backend',
            '@Other/x.html.twig' => 'fremd',
        ]));

        return new ShopLogoInsertTag(new ShopResolver($framework), $pages, $figures, $twig, $files);
    }
}

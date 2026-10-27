<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Seo\InvalidPathTemplate;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPathTemplate;

class ProductPathTemplateTest extends TestCase
{
    public function testAcceptsTheFourKnownPlaceholders(): void
    {
        self::assertSame('{alias}', ProductPathTemplate::fromString('{alias}')->toString());
        self::assertSame('{manufacturer}/{alias}', ProductPathTemplate::fromString('{manufacturer}/{alias}')->toString());
        self::assertSame('{type}/{sku}/{alias}', ProductPathTemplate::fromString('{type}/{sku}/{alias}')->toString());
    }

    /**
     * The SKU is neither unique nor required: two main products with the
     * same number would get one address, one without a number none. {sku}
     * stays allowed next to {alias}.
     */
    public function testDieArtikelnummerAlleinIdentifiziertKeinProdukt(): void
    {
        try {
            ProductPathTemplate::fromString('{type}/{sku}');
            self::fail('{type}/{sku} wurde angenommen');
        } catch (InvalidPathTemplate $e) {
            self::assertSame(InvalidPathTemplate::NO_IDENTIFIER, $e->key);
        }

        self::assertSame('{sku}/{alias}', ProductPathTemplate::fromString('{sku}/{alias}')->toString());
    }

    public function testIsRelativeToThePageAndSaysSoBySheddingSlashes(): void
    {
        /*
         * The template describes what comes AFTER the page, because that is
         * what Contao supports: PageRoute::__construct prefixes any path
         * without a leading slash with the page alias. An absolute template
         * would need a per-type pathRegex, which is compiled once for the whole
         * type and therefore cannot differ per page.
         */
        self::assertSame('{alias}', ProductPathTemplate::fromString('/{alias}/')->toString());
    }

    public function testRejectsAnUnknownPlaceholder(): void
    {
        /*
         * The category is the one people will try, because every other shop
         * allows it. Rejecting it here rather than ignoring it is the point:
         * an ignored placeholder produces an address that looks as if it had
         * worked.
         */
        $this->expectException(InvalidPathTemplate::class);
        $this->expectExceptionMessage('category');

        ProductPathTemplate::fromString('{category}/{alias}');
    }

    public function testRejectsATemplateThatCannotIdentifyAProduct(): void
    {
        // Every product of the same manufacturer would answer on the same
        // address — the failure is total, and silent until someone notices
        // that the shop has one product page.
        $this->expectException(InvalidPathTemplate::class);

        ProductPathTemplate::fromString('{manufacturer}');
    }

    public function testRejectsATemplateWithoutAnyPlaceholder(): void
    {
        $this->expectException(InvalidPathTemplate::class);

        ProductPathTemplate::fromString('produkt');
    }

    public function testRejectsAnEmptyTemplate(): void
    {
        $this->expectException(InvalidPathTemplate::class);

        ProductPathTemplate::fromString('   ');
    }

    public function testRendersTheValues(): void
    {
        $template = ProductPathTemplate::fromString('{manufacturer}/{alias}');

        self::assertSame(
            'kraeuterhaus/schwedenbitter',
            $template->render(['manufacturer' => 'kraeuterhaus', 'alias' => 'schwedenbitter']),
        );
    }

    public function testDropsASegmentWhoseValueIsMissing(): void
    {
        /*
         * A product without a manufacturer still needs an address. Dropping the
         * segment keeps the product identifiable, because the alias is
         * always part of the template — substituting something else would put
         * the same word in twice.
         */
        $template = ProductPathTemplate::fromString('{manufacturer}/{alias}');

        self::assertSame('schwedenbitter', $template->render(['manufacturer' => '', 'alias' => 'schwedenbitter']));
    }

    public function testNeverProducesADoubleSlash(): void
    {
        $template = ProductPathTemplate::fromString('{type}/{manufacturer}/{alias}');

        self::assertSame('schwedenbitter', $template->render(['type' => '', 'manufacturer' => '', 'alias' => 'schwedenbitter']));
    }

    public function testTellsWhichPlaceholdersItUses(): void
    {
        // The recorder in B-2 and the URL generator both need to know which
        // values to look up, rather than loading a manufacturer for every
        // product of a shop that does not use one.
        $template = ProductPathTemplate::fromString('{manufacturer}/{alias}');

        self::assertSame(['manufacturer', 'alias'], $template->placeholders());
    }

    public function testAnIdentifyingPlaceholderMayNotAppearTwice(): void
    {
        // Not a correctness problem, but a sign that someone misunderstood the
        // field — and the resulting address repeats itself.
        $this->expectException(InvalidPathTemplate::class);

        ProductPathTemplate::fromString('{alias}/{alias}');
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\TranslatableFields;

class TranslatableFieldsTest extends TestCase
{
    public function testProductHasAllEightFields(): void
    {
        // metaDescription next to the short text it falls back to. The order
        // is that of the translation sections.
        // customFieldValues since ZW-3: the texts of the custom fields as JSON.
        self::assertSame(
            ['title', 'alias', 'teaser', 'metaDescription', 'description', 'safetyWarnings', 'safetyInformation', 'customFieldValues'],
            TranslatableFields::of('tl_consho_product'),
        );
    }

    public function testPropertyOptionHasOnlyTitle(): void
    {
        self::assertSame(['title'], TranslatableFields::of('tl_consho_property_option'));
    }

    public function testAnUnknownTableHasNoTranslatableFields(): void
    {
        self::assertSame([], TranslatableFields::of('tl_member'));
    }

    public function testItNamesTheTables(): void
    {
        self::assertSame(
            [
                'tl_consho_product',
                'tl_consho_product_type',
                'tl_consho_manufacturer',
                'tl_consho_property_group',
                'tl_consho_property_option',
                'tl_consho_shipping_method',
                'tl_consho_payment_method',
                'tl_consho_shop',
                'tl_consho_custom_field',
            ],
            TranslatableFields::tables(),
        );
    }

    public function testItBuildsTheGeneratedFieldName(): void
    {
        self::assertSame('title__en', TranslatableFields::fieldName('title', 'en'));
    }

    public function testItSplitsAGeneratedFieldNameEvenWithAHyphenatedLanguage(): void
    {
        self::assertSame(['title', 'de-AT'], TranslatableFields::split('title__de-AT'));
    }

    public function testANameWithoutTheSeparatorIsNotAGeneratedField(): void
    {
        self::assertSame([], TranslatableFields::split('title'));
    }

    public function testANameWithTheSeparatorButNoLanguageIsNotAGeneratedField(): void
    {
        self::assertSame([], TranslatableFields::split('title__'));
    }
}

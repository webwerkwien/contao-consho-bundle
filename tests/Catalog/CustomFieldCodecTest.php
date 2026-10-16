<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldCodec;

class CustomFieldCodecTest extends TestCase
{
    private CustomFieldCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new CustomFieldCodec();
    }

    public function testEncodesAndDecodesRoundTrip(): void
    {
        $values = ['material' => 'Leinen', 'weightClass' => 3, 'organic' => true];

        self::assertSame($values, $this->codec->decode($this->codec->encode($values)));
    }

    public function testDecodesNullToAnEmptyArray(): void
    {
        self::assertSame([], $this->codec->decode(null));
    }

    public function testDecodesAnEmptyStringToAnEmptyArray(): void
    {
        self::assertSame([], $this->codec->decode(''));
    }

    public function testDecodesBrokenJsonToAnEmptyArrayInsteadOfThrowing(): void
    {
        // The column can be edited by hand or mangled by a migration. A broken
        // value must not take a product page down with it.
        self::assertSame([], $this->codec->decode('{not json'));
    }

    public function testKeepsUmlautsReadableInsteadOfEscapingThem(): void
    {
        $json = $this->codec->encode(['unit' => 'Größe']);

        // No backslash-u escape sequences: the umlaut stays readable. The
        // column is read by humans during debugging often enough for that to
        // be worth it.
        self::assertStringContainsString('Größe', $json);
        self::assertStringNotContainsString('\u', $json);
    }

    public function testDecodesAJsonScalarToAnEmptyArray(): void
    {
        // Valid JSON, but not an object — it must not pass as a value set.
        self::assertSame([], $this->codec->decode('"a string"'));
        self::assertSame([], $this->codec->decode('42'));
        self::assertSame([], $this->codec->decode('null'));
    }

    public function testEncodesAnEmptyValueSetToAnEmptyObject(): void
    {
        self::assertSame([], $this->codec->decode($this->codec->encode([])));
    }
}

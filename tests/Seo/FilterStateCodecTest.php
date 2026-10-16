<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;

class FilterStateCodecTest extends TestCase
{
    private FilterStateCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new FilterStateCodec();
    }

    public function testEncodesASingleFilter(): void
    {
        $state = new FilterState(['volume' => ['250ml']]);

        self::assertSame('volume:250ml', $this->codec->encode($state));
    }

    public function testEncodesSeveralValuesOfOneFilter(): void
    {
        $state = new FilterState(['volume' => ['250ml', '750ml']]);

        self::assertSame('volume:250ml,750ml', $this->codec->encode($state));
    }

    public function testEncodesSeveralFiltersAndTheSortOrder(): void
    {
        $state = new FilterState(['volume' => ['250ml'], 'colour' => ['red']], 'price_asc');

        self::assertSame('colour:red;volume:250ml;sort_by:price_asc', $this->codec->encode($state));
    }

    /**
     * Block Verfügbarkeit, V5: „Nur verfügbare" mit einem Schlüssel, den kein
     * Gruppen-Alias tragen kann (Unterstrich).
     */
    public function testNurVerfuegbareHinUndZurueck(): void
    {
        $state = new FilterState(['colour' => ['red']], 'price_asc', true);

        self::assertSame('colour:red;only_available:1;sort_by:price_asc', $this->codec->encode($state));

        $decoded = $this->codec->decode('colour:red;only_available:1;sort_by:price_asc');
        self::assertTrue($decoded->availableOnly);
        self::assertSame(['colour' => ['red']], $decoded->filters);
        self::assertSame('price_asc', $decoded->sort);

        self::assertSame('only_available:1', $this->codec->encode(new FilterState([], '', true)));
        self::assertFalse((new FilterState([], '', true))->isEmpty());
    }

    /**
     * Offener Punkt Verfügbarkeit (2026-10-09): Eine Merkmalgruppe darf „sort"
     * heißen. Bis dahin las der Codec „sort:groß" als Sortierung, und die
     * Gruppe war nicht filterbar. Ein alter Sortierlink verliert nur die
     * Sortierung — „sort:price_asc" ist jetzt ein Filter, den keine Liste kennt.
     */
    public function testEineGruppeNamensSortIstFilterbar(): void
    {
        $state = new FilterState(['sort' => ['gross']], 'price_asc');

        self::assertSame('sort:gross;sort_by:price_asc', $this->codec->encode($state));

        $decoded = $this->codec->decode('sort:gross;sort_by:price_asc');
        self::assertSame(['sort' => ['gross']], $decoded->filters);
        self::assertSame('price_asc', $decoded->sort);
    }

    public function testNurDieEinsSchaltetEin(): void
    {
        self::assertFalse($this->codec->decode('only_available:yes')->availableOnly);
        self::assertSame([], $this->codec->decode('only_available:1')->filters, 'keine Filtergruppe namens only_available');
    }

    public function testTheOrderOfFiltersIsStableRegardlessOfInputOrder(): void
    {
        // Two clicks in different orders must produce the SAME url, or the
        // same page exists twice and competes with itself.
        $a = $this->codec->encode(new FilterState(['volume' => ['250ml'], 'colour' => ['red']]));
        $b = $this->codec->encode(new FilterState(['colour' => ['red'], 'volume' => ['250ml']]));

        self::assertSame($a, $b);
    }

    public function testTheOrderOfValuesWithinAFilterIsStableToo(): void
    {
        $a = $this->codec->encode(new FilterState(['volume' => ['750ml', '250ml']]));
        $b = $this->codec->encode(new FilterState(['volume' => ['250ml', '750ml']]));

        self::assertSame($a, $b);
    }

    public function testAnEmptyStateEncodesToNullSoNoParameterIsAppended(): void
    {
        self::assertNull($this->codec->encode(new FilterState()));
    }

    public function testAFilterWithoutOptionsIsNotAFilter(): void
    {
        // A group whose last option was just unticked must leave no trace. An
        // encoded "volume:" would be a second URL for the unfiltered listing.
        self::assertNull($this->codec->encode(new FilterState(['volume' => []])));
        self::assertSame('sort_by:price_asc', $this->codec->encode(new FilterState(['volume' => []], 'price_asc')));
    }

    public function testDecodesWhatItEncoded(): void
    {
        $state = new FilterState(['volume' => ['250ml', '750ml'], 'colour' => ['red']], 'price_asc');

        $decoded = $this->codec->decode($this->codec->encode($state));

        self::assertSame(['colour' => ['red'], 'volume' => ['250ml', '750ml']], $decoded->filters);
        self::assertSame('price_asc', $decoded->sort);
    }

    public function testDecodesNullToAnEmptyState(): void
    {
        self::assertTrue($this->codec->decode(null)->isEmpty());
        self::assertTrue($this->codec->decode('')->isEmpty());
    }

    public function testIgnoresGarbageInsteadOfThrowing(): void
    {
        // The parameter is public and will be tampered with. A product listing
        // must not fall over because someone edited the URL.
        self::assertTrue($this->codec->decode('%%%')->isEmpty());
        self::assertTrue($this->codec->decode('no-colon-here')->isEmpty());
        self::assertTrue($this->codec->decode(';;;')->isEmpty());
    }

    public function testRejectsAliasesThatCannotBeOurs(): void
    {
        // Only lowercase letters, digits and hyphens are legal aliases. This
        // keeps SQL injection attempts out before the value ever reaches a query.
        $decoded = $this->codec->decode("volume:250ml' OR 1=1--");

        self::assertSame([], $decoded->filters);
    }

    public function testRejectsASortOrderThatCannotBeOurs(): void
    {
        // The sort key reaches an ORDER BY. Anything but our own keys is dropped
        // whole — never sanitised into something that still runs.
        self::assertSame('', $this->codec->decode("sort_by:price_asc' OR 1=1--")->sort);
        self::assertSame('', $this->codec->decode('sort_by:grandTotal DESC')->sort);
    }
}

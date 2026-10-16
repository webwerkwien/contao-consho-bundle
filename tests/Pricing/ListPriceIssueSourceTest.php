<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTaxClassesInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\BasePriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceIssueSource;
use Webwerkwien\ContaoConshoBundle\Pricing\ListPriceProjector;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Pricing\ProjectionWriterInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class ListPriceIssueSourceTest extends TestCase
{
    use TaxFixture;

    /**
     * Wie oft verify() die Projektion gelesen hat.
     */
    private int $reads = 0;

    public function testAMatchingProjectionIsNoFinding(): void
    {
        $source = new ListPriceIssueSource($this->projector(stored: '12.0000'), new ArrayAdapter());

        self::assertSame([], $source->issues());
    }

    public function testADeviationIsAWarningWithItsCount(): void
    {
        $source = new ListPriceIssueSource($this->projector(stored: '99.0000'), new ArrayAdapter());

        $issues = $source->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ListPriceDeviation, $issues[0]->finding);
        self::assertSame(['count' => '1'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning(), 'Nichts zum Quittieren: consho:price:rebuild behebt es.');
    }

    public function testTheStartPageDoesNotRecalculateOnEveryCall(): void
    {
        $cache = new ArrayAdapter();
        $source = new ListPriceIssueSource($this->projector(stored: '99.0000'), $cache);

        $source->issues();
        $readsAfterFirstCall = $this->reads;
        $source->issues();

        self::assertSame($readsAfterFirstCall, $this->reads);

        // Was consho:price:rebuild nach dem Beheben tut.
        $cache->delete(ListPriceIssueSource::CACHE_KEY);
        $source->issues();

        self::assertGreaterThan($readsAfterFirstCall, $this->reads);
    }

    private function projector(string $stored): ListPriceProjector
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findProductIdsWithPrices')->willReturn([1]);
        $repository->method('findCurrencies')->willReturn(['EUR']);
        $repository->method('findCandidates')->willReturn([new PriceRow('10.0000', 'EUR', null, '1.0000')]);

        $writer = $this->createMock(ProjectionWriterInterface::class);
        $writer->method('readAll')->willReturnCallback(
            function () use ($stored): array {
                ++$this->reads;

                return [['currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => $stored, 'subscriptionRule' => 0, 'alsoSubscription' => false]];
            },
        );

        $markets = $this->createMock(MarketRepositoryInterface::class);
        $markets->method('findMarkets')->willReturn([new Market('EUR', 'AT')]);

        $taxClasses = $this->createMock(ArticleTaxClassesInterface::class);
        $taxClasses->method('taxClassOf')->willReturn(1);

        return new ListPriceProjector(
            $repository,
            new ArticlePriceResolver(new PriceCalculator($repository), $repository, new BasePriceCalculator(), $this->grossCalculator(), $taxClasses),
            $writer,
            $this->createMock(ArticleTreeInterface::class),
            $markets,
        );
    }
}

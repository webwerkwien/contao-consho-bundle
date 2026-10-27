<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Config;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationCheck;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;

class ConfigurationCheckTest extends TestCase
{
    private ConfigurationCheck $check;

    protected function setUp(): void
    {
        $this->check = new ConfigurationCheck();
    }

    public function testTurnsAnIncompleteCountryIntoAReadableIssue(): void
    {
        $issues = $this->check->fromIncompleteTaxRates(
            ['IT' => [2]],
            [1 => 'Normalsatz', 2 => 'Ermäßigt'],
            'Kräuterhaus',
            7,
        );

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::TaxRateMissing, $issues[0]->finding);
        self::assertSame('IT', $issues[0]->context['country']);
        self::assertSame('Ermäßigt', $issues[0]->context['taxClass']);
    }

    /**
     * 🔴 **The finding says which shop is meant.**
     *
     * Before, it only named country and product group, as the only one of the
     * three here. On an installation with two shops that both deliver to the
     * same country and both lack a rate for it, **two identical lines** stood
     * on the start page — next to shipping method findings that do name the
     * shop.
     *
     * An installation on which only one shop delivers to Denmark never shows
     * it. **That is why nobody noticed** — the environment does not set up the
     * uncomfortable case.
     */
    public function testDerBefundNenntDenShop(): void
    {
        $issues = $this->check->fromIncompleteTaxRates(['IT' => [2]], [2 => 'Ermäßigt'], 'Kräuterhaus', 7);

        self::assertSame('Kräuterhaus', $issues[0]->context['shop']);
        self::assertSame(7, $issues[0]->shopId, 'Wie bei mailTransport und homeCountry.');
    }

    /**
     * Two shops, the same country, the same missing rate: two findings that
     * can be told apart. Exactly the case that used to produce the same
     * sentence twice.
     */
    public function testZweiShopsErgebenZweiUnterscheidbareBefunde(): void
    {
        $issues = [
            ...$this->check->fromIncompleteTaxRates(['DK' => [2]], [2 => 'Ermäßigt'], 'Kräuterhaus', 7),
            ...$this->check->fromIncompleteTaxRates(['DK' => [2]], [2 => 'Ermäßigt'], 'Bergladen', 8),
        ];

        self::assertCount(2, $issues);
        self::assertNotSame($issues[0]->context, $issues[1]->context);
        self::assertSame([7, 8], [$issues[0]->shopId, $issues[1]->shopId]);
    }

    public function testAShopWithoutAHomeCountryIsAWarning(): void
    {
        // No home country, no gross price; no gross price, no prices in the shop (C25).
        // A warning, not confirmable: the shop sells nothing until it is set.
        $issues = $this->check->homeCountry('', 'Kräuterhaus');

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::HomeCountryMissing, $issues[0]->finding);
        self::assertSame('Kräuterhaus', $issues[0]->context['shop']);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame([], $this->check->homeCountry('AT', 'Kräuterhaus'));
    }

    /**
     * The message on saving the shop configuration has said "Dänemark" since
     * A-3. This one saying "DK" for the same finding makes an operator wonder
     * whether they are two different facts.
     */
    public function testTheCountryIsNamedWhenANameIsKnown(): void
    {
        $issues = $this->check->fromIncompleteTaxRates(
            ['DK' => [2]],
            [2 => 'Ermäßigt'],
            'Kräuterhaus',
            7,
            ['DK' => 'Dänemark'],
        );

        self::assertSame('Dänemark', $issues[0]->context['country']);
    }

    public function testTheCodeRemainsWhenNoNameIsKnown(): void
    {
        $issues = $this->check->fromIncompleteTaxRates(['XX' => [2]], [2 => 'Ermäßigt'], 'Kräuterhaus', 7, ['DK' => 'Dänemark']);

        self::assertSame('XX', $issues[0]->context['country']);
    }

    public function testReportsEveryMissingCombinationNotJustTheFirst(): void
    {
        $issues = $this->check->fromIncompleteTaxRates(
            ['IT' => [1, 2], 'FR' => [2]],
            [1 => 'Normalsatz', 2 => 'Ermäßigt'],
            'Kräuterhaus',
            7,
        );

        self::assertCount(3, $issues);
    }

    public function testReportsNothingWhenEverythingIsConfigured(): void
    {
        self::assertSame([], $this->check->fromIncompleteTaxRates([], [1 => 'Normalsatz'], 'Kräuterhaus', 7));
    }

    /**
     * A tax class that was deleted while a rate still pointed at it leaves an
     * id without a title. Naming the id is ugly but findable; silently dropping
     * the issue would hide a country that cannot be priced.
     */
    public function testAnUnknownTaxClassIsStillReported(): void
    {
        $issues = $this->check->fromIncompleteTaxRates(['IT' => [99]], [1 => 'Normalsatz'], 'Kräuterhaus', 7);

        self::assertCount(1, $issues);
        self::assertStringContainsString('99', $issues[0]->context['taxClass']);
    }

    /**
     * One business has one VAT registration. Two shop configurations sharing it
     * are a strong sign that a multilingual shop was set up twice — which
     * splits the invoice number sequence.
     */
    public function testReportsTwoShopConfigurationsSharingAVatId(): void
    {
        $issues = $this->check->duplicateVatIds([
            ['title' => 'Kräuterversand DE', 'vatId' => 'ATU12345678'],
            ['title' => 'Kräuterversand AT', 'vatId' => 'ATU12345678'],
        ]);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::DuplicateVatId, $issues[0]->finding);
        self::assertStringContainsString('Kräuterversand', $issues[0]->context['shops']);
    }

    public function testDifferentVatIdsAreNotReported(): void
    {
        self::assertSame([], $this->check->duplicateVatIds([
            ['title' => 'A', 'vatId' => 'ATU12345678'],
            ['title' => 'B', 'vatId' => 'DE123456789'],
        ]));
    }

    /**
     * Shops that have not entered a VAT ID yet must not all be reported as
     * duplicates of one another — that would fire on every fresh installation
     * with two shops.
     */
    public function testShopsWithoutAVatIdAreNotTreatedAsDuplicates(): void
    {
        self::assertSame([], $this->check->duplicateVatIds([
            ['title' => 'A', 'vatId' => ''],
            ['title' => 'B', 'vatId' => '   '],
        ]));
    }

    /**
     * Case and spacing are formatting, not identity: ATU 123 and atu123 are one
     * registration.
     */
    public function testVatIdComparisonIgnoresCaseAndSpacing(): void
    {
        $issues = $this->check->duplicateVatIds([
            ['title' => 'A', 'vatId' => 'ATU 1234 5678'],
            ['title' => 'B', 'vatId' => 'atu12345678'],
        ]);

        self::assertCount(1, $issues);
    }

    public function testReportsAShopWithoutAMailConfiguration(): void
    {
        $issues = $this->check->mailTransport(0, 'Testshop');

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::MailTransportMissing, $issues[0]->finding);
        self::assertSame('Testshop', $issues[0]->context['shop']);
    }

    public function testAShopWithAMailConfigurationIsNotReported(): void
    {
        self::assertSame([], $this->check->mailTransport(3, 'Testshop'));
    }
}

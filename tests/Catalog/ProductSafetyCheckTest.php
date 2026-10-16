<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyCheck;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;

class ProductSafetyCheckTest extends TestCase
{
    private ProductSafetyCheck $check;

    protected function setUp(): void
    {
        $this->check = new ProductSafetyCheck();
    }

    public function testPhysicalProductsWithoutAManufacturerAreReportedAsACount(): void
    {
        // A count, not a list: a catalogue import can leave hundreds of these,
        // and a start page listing them would be unusable.
        $issues = $this->check->fromProductsWithoutManufacturer(7);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ProductManufacturerMissing, $issues[0]->finding);
        self::assertSame('7', $issues[0]->context['count']);
    }

    public function testNothingIsReportedWhenEveryPhysicalProductHasOne(): void
    {
        self::assertSame([], $this->check->fromProductsWithoutManufacturer(0));
    }

    public function testAManufacturerInsideTheUnionNeedsNoResponsiblePerson(): void
    {
        self::assertSame([], $this->check->fromManufacturers([
            $this->manufacturer(country: 'AT'),
        ]));
    }

    public function testAThirdCountryManufacturerWithoutAResponsiblePersonIsReported(): void
    {
        // 🎯 The case that gets forgotten. A Swiss or British manufacturer is
        // the normal case in an Austrian shop and needs a responsible person
        // inside the Union. Checking only "is a manufacturer set" declares the
        // shop fine.
        $issues = $this->check->fromManufacturers([
            $this->manufacturer(title: 'Swiss Herbs AG', country: 'CH'),
        ]);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ManufacturerEuResponsibleMissing, $issues[0]->finding);
        self::assertSame('Swiss Herbs AG', $issues[0]->context['manufacturer']);
    }

    public function testTheCountryIsNamedTheWayTheTaxFindingNamesIt(): void
    {
        // The tax finding one line above says "Dänemark". This one saying "CH"
        // for the same kind of fact makes an operator wonder whether they are
        // two kinds of fact.
        $issues = $this->check->fromManufacturers(
            [$this->manufacturer(country: 'CH')],
            ['CH' => 'Schweiz'],
        );

        self::assertSame('Schweiz', $issues[0]->context['country']);
    }

    public function testAnUnknownCountryFallsBackToItsCode(): void
    {
        // Naming the bare code is ugly but findable; dropping the finding would
        // hide a manufacturer that may not be offered here at all.
        $issues = $this->check->fromManufacturers([$this->manufacturer(country: 'CH')]);

        self::assertSame('CH', $issues[0]->context['country']);
    }

    public function testAThirdCountryManufacturerWithAResponsiblePersonIsFine(): void
    {
        self::assertSame([], $this->check->fromManufacturers([
            $this->manufacturer(country: 'CH', euResponsible: 4),
        ]));
    }

    public function testAnEeaCountryIsNotTheUnion(): void
    {
        // Norway, Iceland and Liechtenstein are EEA but not EU. A list built
        // from "the European market" instead of the member states would let
        // these through.
        $issues = $this->check->fromManufacturers([
            $this->manufacturer(title: 'Nordic Oils AS', country: 'NO'),
        ]);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ManufacturerEuResponsibleMissing, $issues[0]->finding);
    }

    public function testAManufacturerWithoutAnElectronicAddressIsReported(): void
    {
        $issues = $this->check->fromManufacturers([
            $this->manufacturer(title: 'Kräuterhaus', country: 'AT', email: ''),
        ]);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::ManufacturerEmailMissing, $issues[0]->finding);
        self::assertSame('Kräuterhaus', $issues[0]->context['manufacturer']);
    }

    public function testAManufacturerNoPhysicalProductUsesIsLeftAlone(): void
    {
        // The record may have been created in advance, or belong to goods that
        // are gone. Nagging about it would train the operator to ignore the
        // start page.
        self::assertSame([], $this->check->fromManufacturers([
            $this->manufacturer(country: 'CH', email: '', physicalProducts: 0),
        ]));
    }

    public function testBothGapsOfOneManufacturerAreReported(): void
    {
        $issues = $this->check->fromManufacturers([
            $this->manufacturer(country: 'CN', email: ''),
        ]);

        self::assertCount(2, $issues);
    }

    public function testTwoProductsSharingAGtinAreReported(): void
    {
        $issues = $this->check->fromDuplicateGtins([
            ['gtin' => '4006381333931', 'products' => 2],
        ]);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::DuplicateGtin, $issues[0]->finding);
        self::assertSame('4006381333931', $issues[0]->context['gtin']);
        self::assertSame('2', $issues[0]->context['count']);
    }

    public function testADuplicateGtinIsANoteAndNotAWarning(): void
    {
        // Almost always a mistake, but only almost: a shop may carry the same
        // article twice during a changeover. A finding that accuses where it
        // should ask gets ignored, and then the accusing ones go unread too.
        self::assertFalse(ConfigurationFinding::DuplicateGtin->isWarning());
    }

    public function testNothingIsReportedWhenEveryGtinIsUnique(): void
    {
        self::assertSame([], $this->check->fromDuplicateGtins([]));
    }

    /**
     * @return array<string, mixed>
     */
    private function manufacturer(
        string $title = 'Kräuterhaus Steiermark',
        string $country = 'AT',
        string $email = 'info@example.at',
        int $euResponsible = 0,
        int $physicalProducts = 3,
    ): array {
        return [
            'title' => $title,
            'country' => $country,
            'email' => $email,
            'euResponsible' => $euResponsible,
            'physicalProducts' => $physicalProducts,
        ];
    }
}

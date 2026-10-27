<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Intl\Countries;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ShippingPriceInputListener;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class ShippingPriceInputListenerTest extends TestCase
{
    use TaxFixture;

    /**
     * @var list<array{string, array<int, mixed>}>
     */
    private array $queries = [];

    protected function setUp(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'shippingPriceTaxClassMissing' => 'Versandart ohne Steuerklasse',
            'shippingPriceDuplicate' => 'doppelt %s',
            'priceTaxClassMissing' => 'Produkt ohne Steuerklasse',
        ];
        $GLOBALS['TL_DCA']['tl_consho_shipping_price'] = [
            'palettes' => ['default' => '{price_legend},currency,inputCountry,inputMode,amount'],
            'fields' => ['currency' => [], 'inputCountry' => [], 'inputMode' => [], 'amount' => []],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_LANG'], $GLOBALS['TL_DCA']);
    }

    public function testDieCallbacksHaengenNurAnDerTabelleDerVersandbetraege(): void
    {
        // Mirrors PriceInputListenerTest::testJederCallbackDerPreismaskeIstGenauEinmalRegistriert:
        // every method with the attribute must be declared there as well.
        $targets = [];

        foreach ((new \ReflectionClass(ShippingPriceInputListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                self::assertSame(ShippingPriceInputListener::class, $method->getDeclaringClass()->getName(), $method->getName());

                $arguments = $attribute->getArguments();
                $targets[] = $arguments['table'].' '.$arguments['target'].' '.$method->getName();
            }
        }

        sort($targets);

        self::assertSame([
            'tl_consho_shipping_price config.onbeforesubmit onBeforeSubmit',
            'tl_consho_shipping_price config.oncopy onCopy',
            'tl_consho_shipping_price config.onload onLoad',
            'tl_consho_shipping_price fields.amount.load loadAmount',
            'tl_consho_shipping_price fields.currency.options currencyOptions',
            'tl_consho_shipping_price fields.currency.save saveCurrency',
            'tl_consho_shipping_price fields.inputCountry.options countryOptions',
            'tl_consho_shipping_price fields.inputMode.load loadInputMode',
        ], $targets);
    }

    public function testDieMaskeDerVersandbetraegeWirdVorbelegt(): void
    {
        $this->listener()->onLoad();

        self::assertSame('EUR', $GLOBALS['TL_DCA']['tl_consho_shipping_price']['fields']['currency']['default']);
    }

    public function testBruttoRechnetMitDerBezugsSteuerklasseDerVersandart(): void
    {
        // The worked example: 4.90 gross at 20 %.
        $values = $this->listener(taxClass: 1)->onBeforeSubmit(
            ['currency' => 'EUR', 'inputMode' => 'gross', 'inputCountry' => '', 'amount' => '4.90'],
            $this->dc(),
        );

        self::assertSame('4.0833', $values['net']);
        self::assertContains(['SELECT taxClass FROM tl_consho_shipping_method WHERE id = ?', [3]], $this->queries);
    }

    public function testOhneSteuerklasseNenntDieMeldungDieVersandartNichtDasProdukt(): void
    {
        $this->expectExceptionMessage('Versandart ohne Steuerklasse');

        $this->listener(taxClass: 0)->onBeforeSubmit(
            ['currency' => 'EUR', 'inputMode' => 'gross', 'inputCountry' => '', 'amount' => '4.90'],
            $this->dc(),
        );
    }

    public function testEinZweiterGespeicherterBetragInDerselbenWaehrungWirdAbgelehnt(): void
    {
        // Two rows per currency would be a random shipping price.
        try {
            $this->listener(duplicateId: 12)->onBeforeSubmit(
                ['currency' => 'EUR', 'inputMode' => 'net', 'inputCountry' => '', 'amount' => '5'],
                $this->dc(),
            );
            self::fail('Ein Duplikat wurde gespeichert.');
        } catch (\RuntimeException $e) {
            self::assertSame('doppelt EUR', $e->getMessage());
        }

        // The search is in the same shipping method, the same currency, without the row itself.
        self::assertContains(
            ['SELECT id FROM tl_consho_shipping_price WHERE pid = ? AND currency = ? AND maxWeight = ? AND id != ? AND tstamp > 0', [3, 'EUR', '0.0000', 9]],
            $this->queries,
        );
    }

    /**
     * Block E: the weight scale has one row PER STEP per currency. The
     * duplicate check therefore compares the weight limit as well — entered
     * with a comma, compared as stored.
     */
    public function testBeiDerStaffelZaehltDieStufeMit(): void
    {
        $this->listener()->onBeforeSubmit(
            ['currency' => 'EUR', 'inputMode' => 'net', 'inputCountry' => '', 'amount' => '5', 'maxWeight' => '2,5'],
            $this->dc(),
        );

        self::assertContains(
            ['SELECT id FROM tl_consho_shipping_price WHERE pid = ? AND currency = ? AND maxWeight = ? AND id != ? AND tstamp > 0', [3, 'EUR', '2.5000', 9]],
            $this->queries,
        );
    }

    public function testBeimWaehrungswechselWirdDieNeueWaehrungGeprueft(): void
    {
        // EUR is stored, CHF chosen: an amount in CHF would be the duplicate, not one in EUR.
        $this->listener(markets: [['EUR', 'AT'], ['CHF', 'CH']])->onBeforeSubmit(
            ['currency' => 'CHF', 'inputMode' => 'net', 'inputCountry' => '', 'amount' => '5'],
            $this->dc(),
        );

        self::assertContains(
            ['SELECT id FROM tl_consho_shipping_price WHERE pid = ? AND currency = ? AND maxWeight = ? AND id != ? AND tstamp > 0', [3, 'CHF', '0.0000', 9]],
            $this->queries,
        );
    }

    public function testDieDuplikatpruefungLaeuftAuchOhneBetrag(): void
    {
        // Say, a user group that may only edit the currency.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('doppelt EUR');

        $this->listener(duplicateId: 12)->onBeforeSubmit(['currency' => 'EUR'], $this->dc());
    }

    public function testBeimAutomatischenAbsendenWirdNichtAufDuplikatGeprueft(): void
    {
        // An automatic submit does not really save — the check would come before
        // the actual input and reject an intermediate state.
        $this->listener(duplicateId: 12, submitType: 'auto')->onBeforeSubmit(
            ['currency' => 'EUR', 'inputMode' => 'net', 'inputCountry' => '', 'amount' => '5'],
            $this->dc(),
        );

        $duplicateChecks = array_filter($this->queries, static fn (array $query): bool => str_contains($query[0], 'FROM tl_consho_shipping_price'));

        self::assertSame([], $duplicateChecks);
    }

    public function testOhneDuplikatWirdGespeichert(): void
    {
        $values = $this->listener(duplicateId: false)->onBeforeSubmit(
            ['currency' => 'EUR', 'inputMode' => 'net', 'inputCountry' => '', 'amount' => '5'],
            $this->dc(),
        );

        self::assertSame('5.0000', $values['net']);
    }

    /**
     * @param list<array{string, string}> $markets currency, country
     */
    private function listener(int $taxClass = 1, int|false $duplicateId = false, string|null $submitType = null, array $markets = [['EUR', 'AT']]): ShippingPriceInputListener
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->method('findMarkets')->willReturn(array_map(static fn (array $m): Market => new Market($m[0], $m[1]), $markets));

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'CH' => 'Schweiz']);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql, array $params) use ($taxClass, $duplicateId): int|false {
            $this->queries[] = [$sql, $params];

            if (str_contains($sql, 'FROM tl_consho_shipping_method')) {
                return $taxClass;
            }

            if (str_contains($sql, 'FROM tl_consho_shipping_price')) {
                return $duplicateId;
            }

            // An unexpected query should stand out, not silently count as a duplicate.
            throw new \LogicException('Unerwartete Abfrage: '.$sql);
        });

        $requests = new RequestStack();
        $requests->push(new Request([], null === $submitType ? [] : ['SUBMIT_TYPE' => $submitType]));

        return new ShippingPriceInputListener(
            new HomeCountries($repository),
            new CountryProvider($countries),
            $connection,
            new PriceEntry($this->grossCalculator(['AT' => '20.0000'])),
            $requests,
            static fn (): int => 1_800_000_000,
        );
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn([
            'id' => 9,
            'pid' => 3,
            'tstamp' => 5,
            'currency' => 'EUR',
            'net' => '4.0000',
            'inputMode' => 'net',
            'inputCountry' => '',
        ]);

        return $dc;
    }
}

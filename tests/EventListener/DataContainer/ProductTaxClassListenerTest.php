<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Countries;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductTaxClassListener;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * A product's tax class changes: a price typed as gross keeps its gross, a
 * price typed as net its net.
 */
final class ProductTaxClassListenerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    /**
     * @var list<array{string, string}>
     */
    private array $messages = [];

    protected function setUp(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_LANG']['CONSHO']['taxClassChange'] = ['converted' => 'umgerechnet: %s', 'unconverted' => 'nicht umgerechnet: %s', 'net' => '%s netto'];

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_price');

        // 12,00 € gross at 20 %, typed as gross; 10,00 € net, typed as net.
        $this->price(1, '10.0000', 'gross');
        $this->price(2, '10.0000', 'net');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_LANG']['CONSHO']['taxClassChange']);
    }

    public function testBruttoBleibtNettoBleibt(): void
    {
        $this->save(from: 1, to: 2);

        // 12,00 at 10 %: 10.9091 net.
        self::assertSame('10.9091', $this->net(1));
        self::assertSame('10.0000', $this->net(2));
        self::assertSame([['addInfo', "umgerechnet: 12,00\u{a0}€ (AT)"]], $this->messages);
    }

    public function testOhneWechselNichts(): void
    {
        $this->save(from: 1, to: 1);

        self::assertSame('10.0000', $this->net(1));
        self::assertSame([], $this->messages);
    }

    public function testEinEntwurfBleibtUnberuehrt(): void
    {
        // tstamp 0: opened with "new" and never saved — no price yet.
        $this->connection->update('tl_consho_price', ['tstamp' => 0], ['id' => 1]);

        $this->save(from: 1, to: 2);

        self::assertSame('10.0000', $this->net(1));
    }

    public function testOhneSatzBleibtDerNettobetragUndDieMeldungSagtEs(): void
    {
        $this->save(from: 1, to: 3);

        self::assertSame('10.0000', $this->net(1));
        self::assertSame([['addError', "nicht umgerechnet: 10,00\u{a0}€ netto"]], $this->messages);
    }

    /**
     * Refused in between — onsubmit never comes — the prices stay as they were.
     */
    public function testErstBeimSpeichernWirdGeschrieben(): void
    {
        $listener = $this->listener();
        $listener->onBeforeSubmit(['taxClass' => 2], $this->dc(1));

        self::assertSame('10.0000', $this->net(1));
    }

    /**
     * Changed meanwhile by somebody else: theirs, not this conversion's.
     */
    public function testEineZwischendurchGeaenderteZeileBleibt(): void
    {
        $listener = $this->listener();
        $listener->onBeforeSubmit(['taxClass' => 2], $this->dc(1));
        $this->connection->update('tl_consho_price', ['net' => '20.0000'], ['id' => 1]);
        $listener->onSubmit($this->dc(1));

        self::assertSame('20.0000', $this->net(1));
        self::assertSame([], $this->messages);
    }

    private function save(int $from, int $to): void
    {
        $listener = $this->listener();
        $dc = $this->dc($from);

        $listener->onBeforeSubmit(['taxClass' => $to], $dc);
        $listener->onSubmit($dc);
    }

    private function price(int $id, string $net, string $mode): void
    {
        $this->connection->insert('tl_consho_price', ['id' => $id, 'pid' => 7, 'tstamp' => 5, 'currency' => 'EUR', 'net' => $net, 'inputMode' => $mode, 'inputCountry' => '']);
    }

    /**
     * On four places, as MySQL returns decimal(…,4); SQLite drops the zeros.
     */
    private function net(int $id): string
    {
        return (string) BigDecimal::of((string) $this->connection->fetchOne('SELECT net FROM tl_consho_price WHERE id = ?', [$id]))->toScale(4);
    }

    private function dc(int $taxClass): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['id' => 7, 'taxClass' => $taxClass]);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 7 : null);

        return $dc;
    }

    private function listener(): ProductTaxClassListener
    {
        $markets = $this->createMock(MarketRepositoryInterface::class);
        $markets->method('findMarkets')->willReturn([new Market('EUR', 'AT')]);

        // Class 1 at 20 %, class 2 at 10 %, class 3 without a rate.
        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(
            static fn (int $class, string $country): string|null => match ($class) {
                1 => '20.0000',
                2 => '10.0000',
                default => null,
            },
        );

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT']);

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): null {
            $messages[] = [$name, (string) $args[0]];

            return null;
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(Message::class)->willReturn($message);

        return new ProductTaxClassListener(
            $this->connection,
            new HomeCountries($markets),
            new PriceEntry(new GrossCalculator(new TaxResolver($rates, new CountryProvider($countries)))),
            new MoneyFormatter(),
            $framework,
            static fn (): int => self::NOW,
        );
    }
}

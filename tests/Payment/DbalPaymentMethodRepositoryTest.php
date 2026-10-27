<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalPaymentMethodRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_payment_method');
    }

    public function testVeroeffentlichteArtenInDerReihenfolgeDerAuswahl(): void
    {
        $this->method(2, 'Vorkasse');
        $this->method(6, 'Rechnung', published: 0);
        $this->method(7, 'Karte', type: 'stripe');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'paymentMethods' => serialize(['7', '6', '2'])]);

        self::assertSame([7, 2], array_map(static fn (PaymentMethod $m): int => $m->id, $this->repository()->forShop(1)));
    }

    public function testDerZahlungshinweisKommtUnterInstructionsZuDenBankdaten(): void
    {
        $this->method(2, 'Vorkasse', jsonData: '{"iban":"AT611904300234573201","instructions":"aus einem Modulfeld"}', instructions: '<p>Binnen 7 Tagen.</p>');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'paymentMethods' => serialize(['2'])]);

        $method = $this->repository()->forShop(1)[0];

        self::assertSame(['iban' => 'AT611904300234573201', 'instructions' => '<p>Binnen 7 Tagen.</p>'], $method->config);
        self::assertSame('prepayment', $method->type);
        self::assertSame('Vorkasse', $method->title);
    }

    /**
     * C-9: the note is frozen on the order at purchase — so it must already
     * arrive here in the order's language. The parameters `$language` and
     * `$fallback` existed, but were not used.
     */
    public function testDerZahlungshinweisKommtInDerSpracheDerBestellung(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_translation');
        $this->method(2, 'Vorkasse', instructions: '<p>Binnen 7 Tagen.</p>');
        $this->method(3, 'Rechnung', instructions: '<p>Binnen 14 Tagen.</p>');
        $this->connection->insert('tl_consho_translation', ['pid' => 2, 'ptable' => 'tl_consho_payment_method', 'language' => 'en', 'instructions' => '<p>Within 7 days.</p>']);
        $this->connection->insert('tl_consho_translation', ['pid' => 3, 'ptable' => 'tl_consho_payment_method', 'language' => 'en', 'instructions' => '']);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'paymentMethods' => serialize(['2', '3'])]);

        $en = $this->repository()->forShop(1, 'en', 'de');

        self::assertSame('<p>Within 7 days.</p>', $en[0]->config['instructions']);
        // Translated as empty: the main text, as everywhere (TranslationJoin::coalesce).
        self::assertSame('<p>Binnen 14 Tagen.</p>', $en[1]->config['instructions']);
        self::assertSame('<p>Binnen 7 Tagen.</p>', $this->repository()->forShop(1, 'de', 'de')[0]->config['instructions']);
    }

    public function testEineNieGespeicherteArtWirdUebersprungenAuchWennVeroeffentlicht(): void
    {
        // act=toggle skips the form's required checks. A row that was never
        // saved therefore has type '', but can still be 'published'.
        $this->method(2, 'Ohne Typ', type: '');
        $this->method(3, 'Vorkasse', type: 'prepayment');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'paymentMethods' => serialize(['2', '3'])]);

        self::assertSame([3], array_map(static fn (PaymentMethod $m): int => $m->id, $this->repository()->forShop(1)));
    }

    /**
     * C-11: a notification needs the secret of its method, even when the method
     * has since been hidden or deselected — money that has arrived is booked
     * all the same.
     */
    public function testNachIdAuchUnveroeffentlichtUndOhneShop(): void
    {
        $this->method(6, 'Test', published: 0, type: 'test', jsonData: '{"testSecret":"geheim-geheim-geheim"}');
        $this->method(8, 'Leer', type: '');

        $method = $this->repository()->byId(6);

        self::assertSame('test', $method?->type);
        self::assertSame('geheim-geheim-geheim', $method?->config['testSecret'] ?? null);
        self::assertNull($this->repository()->byId(8));
        self::assertNull($this->repository()->byId(99));
    }

    private function repository(): DbalPaymentMethodRepository
    {
        return new DbalPaymentMethodRepository($this->connection);
    }

    private function method(int $id, string $title, int $published = 1, string $type = 'prepayment', string|null $jsonData = null, string|null $instructions = null): void
    {
        $this->connection->insert('tl_consho_payment_method', [
            'id' => $id, 'tstamp' => 5, 'type' => $type, 'title' => $title,
            'published' => $published, 'jsonData' => $jsonData, 'instructions' => $instructions,
        ]);
    }
}

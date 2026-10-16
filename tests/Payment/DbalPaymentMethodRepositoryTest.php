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
     * C-9: Der Hinweis wird beim Kauf an der Bestellung eingefroren (Michael,
     * 2026-09-25: „den Hinweis beim Kauf an der Bestellung einfrieren → OK")
     * — also muss er hier schon in der Sprache der Bestellung ankommen. Die
     * Parameter `$language` und `$fallback` gab es, benutzt wurden sie nicht.
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
        // Leer übersetzt: der Haupttext, wie überall (TranslationJoin::coalesce).
        self::assertSame('<p>Binnen 14 Tagen.</p>', $en[1]->config['instructions']);
        self::assertSame('<p>Binnen 7 Tagen.</p>', $this->repository()->forShop(1, 'de', 'de')[0]->config['instructions']);
    }

    public function testEineNieGespeicherteArtWirdUebersprungenAuchWennVeroeffentlicht(): void
    {
        // Nachtrag zum Review von Task 4: act=toggle überspringt die
        // Pflichtprüfungen der Maske. Eine nie gespeicherte Zeile hat also
        // type '', kann aber trotzdem 'published' sein.
        $this->method(2, 'Ohne Typ', type: '');
        $this->method(3, 'Vorkasse', type: 'prepayment');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'paymentMethods' => serialize(['2', '3'])]);

        self::assertSame([3], array_map(static fn (PaymentMethod $m): int => $m->id, $this->repository()->forShop(1)));
    }

    /**
     * C-11: Eine Benachrichtigung braucht das Geheimnis ihrer Art, auch wenn
     * die Art inzwischen verborgen oder abgewählt ist — angekommenes Geld wird
     * trotzdem gebucht.
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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalGroupPriceAboveListIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

/**
 * Eine Kundengruppe, die mehr zahlt als alle anderen (C-4).
 *
 * Erlaubt — „Ich denke ja, aber vielleicht sollte es einen Hinweis geben"
 * (Michael, 2026-09-21). Geprüft wird deshalb nicht, ob es vorkommen darf,
 * sondern ob der Hinweis die Fälle trifft, in denen ein Mitglied wirklich
 * mehr zahlt.
 */
class DbalGroupPriceAboveListIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->insertProduct(['id' => 1, 'title' => 'Kräutersalz']);
        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
    }

    public function testOhneGruppenpreiseGibtEsNichtsZuMelden(): void
    {
        $this->price('10.0000');

        self::assertSame([], $this->issues());
    }

    public function testEinGuenstigererGruppenpreisIstDerNormalfall(): void
    {
        $this->price('10.0000');
        $this->price('8.0000', group: 3);

        self::assertSame([], $this->issues());
    }

    public function testEinAbopreisDerGruppeIstKeinTeurererListenpreis(): void
    {
        // Abo-1 (A2): 12 € im Monatsabo neben 10 € Einmalkauf ist kein Vergleich.
        $this->price('10.0000');
        $this->connection->insert('tl_consho_price', ['pid' => 1, 'tstamp' => 1, 'currency' => 'EUR', 'net' => '12.0000', 'customerGroup' => 3, 'subscriptionRule' => 5]);

        self::assertSame([], $this->issues());
    }

    public function testEinTeurererGruppenpreisAufDerselbenSchwelleWirdGemeldet(): void
    {
        // Der Fall, den Michael auf c5 gesehen hat: Liste 7,42 — Gruppe 8,00.
        $this->price('7.4167');
        $this->price('8.0000', group: 3);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::GroupPriceAboveList, $issues[0]->finding);
        self::assertSame('1', $issues[0]->context['count']);
        self::assertSame('Kräutersalz / Händler (ab 1)', $issues[0]->context['examples']);
    }

    public function testAuchWennDerGruppenpreisErstOberhalbEinerStaffelTeurerWird(): void
    {
        /*
         * ⚠️ Der Fall, den ein einfacher Zeilenvergleich verfehlte: Die
         * Gruppenzeile (9,00 ab 1) ist günstiger als der Listenpreis (10,00 ab
         * 1) — erst ab 100 Stück, wo alle anderen 7,00 zahlen, wird sie
         * teurer. Verglichen wird deshalb an JEDER Schwelle, und zwar mit dem
         * echten Rechner.
         */
        $this->price('10.0000');
        $this->price('7.0000', minQuantity: '100.0000');
        $this->price('9.0000', group: 3);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame('Kräutersalz / Händler (ab 100)', $issues[0]->context['examples']);
    }

    public function testEineEigeneStaffelDerGruppeHaeltDenHinweisFern(): void
    {
        // Dieselben Zeilen wie oben, plus eine Gruppenstaffel ab 100, die
        // unter dem öffentlichen Preis bleibt. Dann zahlt das Mitglied
        // nirgends mehr — und ein Hinweis wäre falsch.
        $this->price('10.0000');
        $this->price('7.0000', minQuantity: '100.0000');
        $this->price('9.0000', group: 3);
        $this->price('6.5000', group: 3, minQuantity: '100.0000');

        self::assertSame([], $this->issues());
    }

    public function testEineAbgelaufeneGruppenzeileIstNiemandesPreis(): void
    {
        $this->price('7.0000');
        $this->price('9.0000', group: 3, validTo: self::NOW - 1);

        self::assertSame([], $this->issues());
    }

    public function testEineNieGespeichertePreiszeileZaehltNicht(): void
    {
        // tstamp 0 — dieselbe Regel wie in DbalPriceRepository::findCandidates().
        $this->price('7.0000');
        $this->connection->insert('tl_consho_price', [
            'pid' => 1,
            'tstamp' => 0,
            'currency' => 'EUR',
            'net' => '9.0000',
            'customerGroup' => 3,
            'minQuantity' => '1.0000',
        ]);

        self::assertSame([], $this->issues());
    }

    public function testEineAndereWaehrungMischtSichNichtEin(): void
    {
        // 9 CHF sind nicht mehr als 10 EUR, und der Vergleich findet auch gar
        // nicht statt: Preise verschiedener Währungen begegnen einander nie.
        $this->price('10.0000');
        $this->price('9.0000', group: 3, currency: 'CHF');

        self::assertSame([], $this->issues());
    }

    public function testOhneOeffentlichenPreisGibtEsNichtsZuVergleichen(): void
    {
        // Nur ein Gruppenpreis: Es gibt keinen Betrag, teurer ALS den der
        // Gruppenpreis sein könnte.
        $this->price('9.0000', group: 3);

        self::assertSame([], $this->issues());
    }

    public function testJedesProduktUndJedeGruppeZaehltEinmal(): void
    {
        $this->insertProduct(['id' => 2, 'title' => 'Schwedenbitter']);
        $this->insertProduct(['id' => 3, 'title' => 'Ringelblumensalbe']);
        $this->insertProduct(['id' => 4, 'title' => 'Johanniskrautöl']);
        $this->connection->insert('tl_member_group', ['id' => 4, 'name' => 'Großkunden']);

        foreach ([1, 2, 3, 4] as $product) {
            $this->price('7.0000', product: $product);
            $this->price('9.0000', product: $product, group: 3);
            // Eine zweite Schwelle, die es ebenfalls treffen würde — sie darf
            // den Zähler nicht ein zweites Mal erhöhen.
            $this->price('9.5000', product: $product, group: 3, minQuantity: '10.0000');
        }

        // Dasselbe Produkt, zweite Gruppe: zählt eigens.
        $this->price('9.0000', group: 4);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame('5', $issues[0]->context['count']);
        // Drei Beispiele, dann Auslassungszeichen.
        self::assertStringEndsWith(' …', $issues[0]->context['examples']);
        self::assertSame(3, substr_count($issues[0]->context['examples'], ' / '));
    }

    public function testDerBefundIstEinHinweisUndSperrtNichts(): void
    {
        // Michael hat entschieden, dass ein teurerer Gruppenpreis erlaubt ist.
        // Eine Warnung sperrte den Checkout eines Shops, der genau das tut,
        // was eingestellt wurde.
        self::assertFalse(ConfigurationFinding::GroupPriceAboveList->isWarning());
    }

    private function price(string $net, int $product = 1, int $group = 0, string $minQuantity = '1.0000', string $currency = 'EUR', int|null $validTo = null): void
    {
        $this->connection->insert('tl_consho_price', [
            'pid' => $product,
            'tstamp' => 1,
            'currency' => $currency,
            'net' => $net,
            'customerGroup' => $group,
            'minQuantity' => $minQuantity,
            'validTo' => $validTo,
        ]);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalGroupPriceAboveListIssueSource($this->connection))->issues();
    }
}

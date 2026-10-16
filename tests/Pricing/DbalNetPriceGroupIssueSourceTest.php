<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalNetPriceGroupIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;

class DbalNetPriceGroupIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);

        $this->connection->insert('tl_member_group', ['id' => 3, 'name' => 'Händler']);
        $this->connection->insert('tl_member_group', ['id' => 4, 'name' => 'Großkunden']);
    }

    public function testEinShopOhneNettopreisgruppenMeldetNichts(): void
    {
        $this->shop();

        self::assertSame([], $this->issues());
    }

    public function testVorhandeneGruppenMeldenNichts(): void
    {
        $this->shop([3, 4]);

        self::assertSame([], $this->issues());
    }

    public function testEineGeloeschteGruppeWirdGemeldet(): void
    {
        $this->shop([3, 99]);

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::NetPriceGroupMissing, ['shop' => 'Testshop', 'groups' => '99'])],
            $this->issues(),
        );
    }

    /**
     * ⚠️ Im Abschlussreview C-4 gefunden, in zwei Runden vollständig:
     * FrontendUser::setUserFromDb() schneidet die Gruppen des Mitglieds mit
     * MemberGroupModel::findAllActive(). Deaktiviert, noch nicht begonnen und
     * abgelaufen erreichen den Resolver gleichermaßen nie — die Auswahl nennt
     * sie weiter, die Anzeige fällt auf brutto zurück, und über die Ursache
     * stand nirgends etwas.
     *
     * Die erste Korrektur kannte nur `disable`. Die Zeitgrenzen fielen durch
     * dieselbe Lücke, und ein Test hätte es auch nicht bemerkt: Die Spalten
     * fehlten im Testschema.
     *
     * @dataProvider unerreichbareGruppen
     *
     * @param array<string, mixed> $state
     */
    public function testEineUnerreichbareGruppeZaehltWieEineGeloeschte(array $state): void
    {
        $this->connection->update('tl_member_group', $state, ['id' => 4]);
        $this->shop([3, 4]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame('4', $issues[0]->context['groups']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unerreichbareGruppen(): iterable
    {
        yield 'deaktiviert' => [['disable' => 1]];
        yield 'beginnt erst' => [['start' => (string) (time() + 3_600)]];
        yield 'abgelaufen' => [['stop' => (string) (time() - 3_600)]];
    }

    public function testEineZeitlichGueltigeGruppeIstInOrdnung(): void
    {
        // Die Gegenprobe: Gesetzte Zeitgrenzen sind kein Befund, solange sie
        // jetzt greifen. Ohne sie wäre der Test oben auch mit „meldet immer"
        // grün.
        $this->connection->update(
            'tl_member_group',
            ['start' => (string) (time() - 3_600), 'stop' => (string) (time() + 3_600)],
            ['id' => 4],
        );
        $this->shop([3, 4]);

        self::assertSame([], $this->issues());
    }

    public function testJederShopBekommtSeinenEigenenBefund(): void
    {
        // Ein Satz, der drei Shops nennt, muss dreimal gelesen werden, um zu
        // klären, welcher gemeint ist — und repariert wird in der Maske eines
        // einzelnen.
        $this->shop([99], 'Testshop');
        $this->shop([98], 'Testshop 2');

        $issues = $this->issues();

        self::assertCount(2, $issues);
        self::assertSame('Testshop', $issues[0]->context['shop']);
        self::assertSame('Testshop 2', $issues[1]->context['shop']);
    }

    public function testMehrereFehlendeGruppenStehenSortiertInEinemBefund(): void
    {
        // Sortiert, damit der Fingerabdruck nicht an der Reihenfolge hängt, in
        // der der Betreiber angehakt hat: eine Quittierung, die beim nächsten
        // Speichern zerfällt, ist keine.
        $this->shop([99, 3, 98]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame('98, 99', $issues[0]->context['groups']);
    }

    public function testDerBefundIstEinHinweisUndSperrtNichts(): void
    {
        // Die Preise stimmen, der Shop verkauft weiter — nur eine Anzeige ist
        // falsch. Eine Warnung sperrte den Checkout eines Shops, an dem
        // rechnerisch nichts fehlt.
        self::assertFalse(ConfigurationFinding::NetPriceGroupMissing->isWarning());
    }

    /**
     * @param list<int> $groups
     */
    private function shop(array $groups = [], string $title = 'Testshop'): void
    {
        $this->insertShop([
            'title' => $title,
            // Wie Contao es schreibt: serialize(), nicht JSON.
            'netPriceGroups' => [] === $groups ? null : serialize(array_map('strval', $groups)),
        ]);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalNetPriceGroupIssueSource($this->connection))->issues();
    }
}

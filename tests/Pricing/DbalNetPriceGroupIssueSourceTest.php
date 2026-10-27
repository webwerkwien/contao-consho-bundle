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
     * ⚠️ FrontendUser::setUserFromDb() intersects the member's groups with
     * MemberGroupModel::findAllActive(). Disabled, not yet started and
     * expired groups alike never reach the resolver — the selection still names
     * them, the display falls back to gross, and nothing anywhere
     * said anything about the cause.
     *
     * The first fix only knew `disable`. The time limits slipped through
     * the same gap, and a test would not have noticed either: the columns
     * were missing from the test schema.
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
        // The counter-check: set time limits are not a finding as long as they
        // apply now. Without it the test above would be green even with "always reports".
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
        // A sentence naming three shops has to be read three times to
        // work out which one is meant — and the repair happens in the form of a
        // single one.
        $this->shop([99], 'Testshop');
        $this->shop([98], 'Testshop 2');

        $issues = $this->issues();

        self::assertCount(2, $issues);
        self::assertSame('Testshop', $issues[0]->context['shop']);
        self::assertSame('Testshop 2', $issues[1]->context['shop']);
    }

    public function testMehrereFehlendeGruppenStehenSortiertInEinemBefund(): void
    {
        // Sorted, so that the fingerprint does not depend on the order in
        // which the operator ticked: an acknowledgement that falls apart at the next
        // save is none.
        $this->shop([99, 3, 98]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame('98, 99', $issues[0]->context['groups']);
    }

    public function testDerBefundIstEinHinweisUndSperrtNichts(): void
    {
        // The prices are right, the shop keeps selling — only a display is
        // wrong. A warning would block the checkout of a shop in which
        // nothing is missing arithmetically.
        self::assertFalse(ConfigurationFinding::NetPriceGroupMissing->isWarning());
    }

    /**
     * @param list<int> $groups
     */
    private function shop(array $groups = [], string $title = 'Testshop'): void
    {
        $this->insertShop([
            'title' => $title,
            // As Contao writes it: serialize(), not JSON.
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

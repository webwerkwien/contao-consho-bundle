<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalTextKind;
use Webwerkwien\ContaoConshoBundle\Checkout\ShopLegalRequirements;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * D-2, E1 — Michael, 2026-09-27: „Sperre bei AGB ist schon falsch, man muss
 * keine haben. Sonst nur sperrend, wenn man in der EU Betreiber ist." EWR
 * zählt mit („ja").
 */
final class ShopLegalRequirementsTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, string, int, list<LegalTextKind>}>
     */
    public static function faelle(): iterable
    {
        yield 'Häkchen, Österreich' => [true, 'AT', 14, [LegalTextKind::Terms, LegalTextKind::Withdrawal]];
        yield 'ohne Häkchen, Österreich' => [false, 'AT', 14, [LegalTextKind::Withdrawal]];
        yield 'ohne Häkchen, Liechtenstein' => [false, 'LI', 14, [LegalTextKind::Withdrawal]];
        yield 'ohne Häkchen, Schweiz' => [false, 'CH', 14, []];
        yield 'Häkchen, Schweiz' => [true, 'CH', 14, [LegalTextKind::Terms]];
        yield 'Österreich ohne Widerrufsfunktion' => [false, 'AT', 0, []];
        yield 'noch kein Heimatland' => [false, '', 14, []];
    }

    /**
     * @param list<LegalTextKind> $expected
     *
     * @dataProvider faelle
     */
    public function testWelcheTexteVerlangtSind(bool $termsRequired, string $country, int $days, array $expected): void
    {
        self::assertSame($expected, ShopLegalRequirements::of($termsRequired, $country, $days));
    }

    public function testAusDerShopKonfiguration(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_shop');
        $connection->insert('tl_consho_shop', ['id' => 1, 'country' => 'DE']);

        $requirements = new ShopLegalRequirements($connection);

        self::assertSame([LegalTextKind::Withdrawal], $requirements->forShop(1));
        self::assertSame(LegalTextKind::cases(), $requirements->forShop(99), 'unbekannt: alles, die sichere Antwort');
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModeSettings;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRule;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;

/**
 * Regeln: 5 Monatsabo (unbefristet), 9 Jahresabo nur für Gruppe 3,
 * 11 Jahresabo „gleiche Laufzeit", 12 Vierteljahresabo.
 */
class SubscriptionOffersTest extends TestCase
{
    public function testNurEinmalkauf(): void
    {
        $offers = $this->offers(new SaleModeSettings(true, []));

        self::assertSame([0 => null], $offers->offered(1, []));
    }

    public function testEinmalkaufZuerstDannDieReihenfolgeDesBetreibers(): void
    {
        $offers = $this->offers(new SaleModeSettings(true, [12, 5]));

        self::assertSame([0, 12, 5], array_keys($offers->offered(1, [])));
    }

    public function testKundengruppenDerRegel(): void
    {
        $offers = $this->offers(new SaleModeSettings(false, [5, 9]));

        self::assertSame([5], array_keys($offers->offered(1, [4])));
        self::assertSame([5, 9], array_keys($offers->offered(1, [3, 4])));
    }

    /**
     * Variante A (2026-10-08): „volle Mindestlaufzeit" ist nicht mehr fest
     * Geschäftskunden vorbehalten — eine offene Regel steht allen offen, die
     * Kundengruppen beschränken (Regel 9 hier für Gruppe 3).
     */
    public function testGleicheLaufzeitEntscheidenNurDieGruppen(): void
    {
        $offers = $this->offers(new SaleModeSettings(false, [11, 5]));

        self::assertSame([11, 5], array_keys($offers->offered(1, [])));

        $rules = $this->rules();
        $rules[11] = new SubscriptionRule(11, 'Jahresabo B2B', 1, 'year', 0, RenewalKind::SameTerm, 14, 30, 14, [3]);
        self::assertSame([5], array_keys($this->offers(new SaleModeSettings(false, [11, 5]), $rules)->offered(1, [4])));
        self::assertSame([11, 5], array_keys($this->offers(new SaleModeSettings(false, [11, 5]), $rules)->offered(1, [3])));
    }

    public function testAllows(): void
    {
        $offers = $this->offers(new SaleModeSettings(false, [5]));

        self::assertTrue($offers->allows(1, 5, []));
        // Nicht als Einmalkauf verkauft, und eine Regel, die das Produkt nicht hat.
        self::assertFalse($offers->allows(1, 0, []));
        self::assertFalse($offers->allows(1, 7, []));
        self::assertFalse($offers->allows(1, -1, []));
    }

    /**
     * @param array<int, SubscriptionRule>|null $rules
     */
    private function offers(SaleModeSettings $settings, array|null $rules = null): SubscriptionOffers
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $all = $rules ?? $this->rules();

        $saleModes = new class($connection, $settings) extends SaleModes {
            public function __construct(\Doctrine\DBAL\Connection $connection, private readonly SaleModeSettings $settings)
            {
                parent::__construct($connection);
            }

            public function of(int $articleId): SaleModeSettings
            {
                return $this->settings;
            }
        };

        $ruleReader = new class($connection, $all) extends SubscriptionRules {
            /**
             * @param array<int, SubscriptionRule> $all
             */
            public function __construct(\Doctrine\DBAL\Connection $connection, private readonly array $all)
            {
                parent::__construct($connection);
            }

            public function byIds(array $ids): array
            {
                $found = [];

                foreach ($ids as $id) {
                    if (isset($this->all[$id])) {
                        $found[$id] = $this->all[$id];
                    }
                }

                return $found;
            }
        };

        return new SubscriptionOffers($saleModes, $ruleReader);
    }

    /**
     * @return array<int, SubscriptionRule>
     */
    private function rules(): array
    {
        return [
            5 => new SubscriptionRule(5, 'Monatsabo', 1, 'year', 1, RenewalKind::Indefinite, 14, 30, 14, []),
            9 => new SubscriptionRule(9, 'Jahresabo Gruppe 3', 1, 'year', 0, RenewalKind::Ends, 14, 30, 14, [3]),
            11 => new SubscriptionRule(11, 'Jahresabo B2B', 1, 'year', 0, RenewalKind::SameTerm, 30, 60, 14, []),
            12 => new SubscriptionRule(12, 'Vierteljahr', 1, 'year', 3, RenewalKind::Ends, 14, 30, 14, []),
        ];
    }
}

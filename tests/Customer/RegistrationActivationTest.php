<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Contao\Module;
use Contao\ModuleModel;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Customer\RegistrationActivation;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Nachlese KA1/KA3: "activate accounts immediately" on the registration
 * module — account active, the shop's customer group added, the module's
 * groups stay.
 */
final class RegistrationActivationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_member');
        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop', 'customerGroup' => 7]);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Shop', 'consho_shop' => 1]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'title' => 'Blog']);

        // As Contao saved the member before the hook: disabled, with reg_groups.
        $this->connection->insert('tl_member', ['id' => 5, 'username' => 'kunde', 'disable' => 1, '"groups"' => serialize(['3'])]);
    }

    public function testSchaltetFreiUndFuegtKundengruppeHinzu(): void
    {
        $this->activation()->activate(5, ['groups' => serialize(['3'])], 1);

        self::assertSame(['disable' => 0, 'groups' => ['3', '7']], $this->member());
    }

    public function testKundengruppeSchonDabeiKeinDoppel(): void
    {
        $this->activation()->activate(5, ['groups' => serialize(['7'])], 1);

        self::assertSame(['7'], $this->member()['groups']);
    }

    public function testOhneShopAmStartpunktNurFreigeschaltet(): void
    {
        $this->activation()->activate(5, ['groups' => serialize(['3'])], 2);

        self::assertSame(['disable' => 0, 'groups' => ['3']], $this->member());
    }

    public function testOhneGruppenNurKundengruppe(): void
    {
        $this->activation()->activate(5, ['groups' => ''], 1);

        self::assertSame(['7'], $this->member()['groups']);
    }

    public function testHookOhneSchalterTutNichts(): void
    {
        $this->activation(1)($this->member5(), ['groups' => serialize(['3'])], $this->module(false));

        self::assertSame(['disable' => 1, 'groups' => ['3']], $this->member());
    }

    public function testHookMitSchalterNimmtDenStartpunktDerSeite(): void
    {
        $this->activation(1)($this->member5(), ['groups' => serialize(['3'])], $this->module(true));

        self::assertSame(['disable' => 0, 'groups' => ['3', '7']], $this->member());
    }

    private function member5(): string
    {
        // Contao passes the ID as a number or a string, depending on the driver.
        return '5';
    }

    private function activation(int|null $rootOfPage = null): RegistrationActivation
    {
        $requests = new RequestStack();

        if (null !== $rootOfPage) {
            $page = $this->createMock(PageModel::class);
            $page->method('__get')->willReturnMap([['type', 'regular'], ['rootId', $rootOfPage], ['id', 9]]);

            $request = new Request();
            $request->attributes->set('pageModel', $page);
            $requests->push($request);
        }

        return new RegistrationActivation($this->connection, $requests);
    }

    private function module(bool $atOnce): Module
    {
        $model = $this->createMock(ModuleModel::class);
        $model->method('row')->willReturn(['type' => 'registration', 'consho_activateAtOnce' => $atOnce ? '1' : '']);

        $module = $this->createMock(Module::class);
        $module->method('getModel')->willReturn($model);

        return $module;
    }

    /**
     * @return array{disable: int, groups: list<string>}
     */
    private function member(): array
    {
        $row = $this->connection->fetchAssociative('SELECT disable, "groups" FROM tl_member WHERE id = 5');
        self::assertIsArray($row);

        /** @var list<string> $groups */
        $groups = unserialize((string) $row['groups']);

        return ['disable' => (int) $row['disable'], 'groups' => $groups];
    }
}

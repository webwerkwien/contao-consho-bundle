<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Contao\DataContainer;
use Contao\FrontendUser;
use Contao\Module;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Address\VatIdValidatorInterface;
use Webwerkwien\ContaoConshoBundle\Customer\MemberVatId;
use Webwerkwien\ContaoConshoBundle\Customer\MemberVatIdListener;
use Webwerkwien\ContaoConshoBundle\Customer\MemberVatIdUpdate;
use Webwerkwien\ContaoConshoBundle\Customer\NetGroupAssigner;
use Webwerkwien\ContaoConshoBundle\Customer\RegistrationActivation;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Registration, "Persönliche Daten" and the back end check the member's VAT
 * ID and add the net price groups; the back end confirms what was added.
 */
final class MemberVatIdListenerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string|null}> */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_member');
        DcaSchema::createCore($this->connection, 'tl_member_group');
        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        foreach ([3, 7, 8] as $id) {
            $this->connection->insert('tl_member_group', ['id' => $id, 'name' => 'Gruppe '.$id]);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop', 'vatId' => 'ATU11111111', 'customerGroup' => 7, 'netPriceGroups' => serialize(['8']), 'vatIdNetGroups' => 1]);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Shop', 'consho_shop' => 1]);
        $this->connection->insert('tl_member', ['id' => 5, 'username' => 'kunde', 'disable' => 1, '"groups"' => serialize(['3']), 'consho_vatId' => 'DE123456789']);
    }

    public function testRegistrierungPrueftMitDerUidDesShopsUndOrdnetZu(): void
    {
        $this->listener()->onRegistration('5', ['consho_vatId' => 'DE123456789'], $this->module());

        self::assertSame([['DE123456789', 'ATU11111111']], $this->queries);
        self::assertSame(['3', '8'], $this->member()['groups']);
        self::assertSame(['8'], $this->member()['auto']);
    }

    public function testRegistrierungOhneUidFragtNicht(): void
    {
        $this->listener()->onRegistration('5', ['consho_vatId' => ' '], $this->module());

        self::assertSame([], $this->queries);
        self::assertSame(['3'], $this->member()['groups']);
    }

    /**
     * The order the priorities give: RegistrationActivation writes the
     * groups from the form data first, the net price groups come after.
     */
    public function testNachDerSofortfreischaltungBleibenBeideGruppen(): void
    {
        (new RegistrationActivation($this->connection, $this->requests()))->activate(5, ['groups' => serialize(['3'])], 1);
        $this->listener()->onRegistration('5', ['consho_vatId' => 'DE123456789'], $this->module());

        self::assertSame(['3', '7', '8'], $this->member()['groups']);
    }

    public function testPersoenlicheDatenPruefen(): void
    {
        $this->listener()->onPersonalData($this->user(), [], $this->module());

        self::assertCount(1, $this->queries);
        self::assertSame(['3', '8'], $this->member()['groups']);
    }

    public function testPersoenlicheDatenOhneAenderungFragenNichtErneut(): void
    {
        $this->listener()->onPersonalData($this->user(), [], $this->module());
        $this->listener()->onPersonalData($this->user(), [], $this->module());

        self::assertSame(1, $this->queryCount());
    }

    public function testFrontendSpeichernLaesstDenVermerkStehen(): void
    {
        $this->connection->update('tl_member', ['consho_netGroupsAuto' => serialize(['8']), 'consho_netGroupsAutoAt' => 100], ['id' => 5]);

        $this->listener()->onBackendSubmit($this->user());

        self::assertSame(['8'], $this->member()['auto']);
        self::assertSame([], $this->queries);
    }

    public function testBackendBestaetigtDieGruppenUndPrueft(): void
    {
        $this->connection->update('tl_member', ['"groups"' => serialize(['3', '7', '8']), 'consho_netGroupsAuto' => serialize(['8']), 'consho_netGroupsAutoAt' => 100], ['id' => 5]);

        $this->listener()->onBackendSubmit($this->dc());

        // Requester: the shop whose customer group the member is in.
        self::assertSame([['DE123456789', 'ATU11111111']], $this->queries);
        self::assertSame(['groups' => ['3', '7', '8'], 'auto' => [], 'at' => null], $this->member());
    }

    public function testBackendOhneKundengruppeNimmtDieEinzigeShopUid(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zweiter', 'vatId' => 'atu 11111111', 'customerGroup' => 9]);

        $this->listener()->onBackendSubmit($this->dc());

        self::assertSame([['DE123456789', 'ATU11111111']], $this->queries);
    }

    public function testBackendBeiMehrerenFirmenOhneKundengruppeOhneAnfragendeUid(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Andere Firma', 'vatId' => 'ATU22222222', 'customerGroup' => 9]);

        $this->listener()->onBackendSubmit($this->dc());

        self::assertSame([['DE123456789', null]], $this->queries);
    }

    private function listener(): MemberVatIdListener
    {
        $validator = new class($this) implements VatIdValidatorInterface {
            public function __construct(private readonly MemberVatIdListenerTest $test)
            {
            }

            public function check(string $vatId, string|null $requesterVatId = null): VatIdCheckResult
            {
                return $this->test->answer($vatId, $requesterVatId);
            }
        };

        $update = new MemberVatIdUpdate($this->connection, new MemberVatId($this->connection, $validator), new NetGroupAssigner($this->connection));

        return new MemberVatIdListener($this->connection, $update, new RegistrationActivation($this->connection, $this->requests()));
    }

    /**
     * @internal for the validator above
     */
    public function answer(string $vatId, string|null $requester): VatIdCheckResult
    {
        $this->queries[] = [$vatId, $requester];

        return new VatIdCheckResult(VatIdStatus::Valid, time(), null, null === $requester ? null : 'WAPI1');
    }

    private function queryCount(): int
    {
        return \count($this->queries);
    }

    private function requests(): RequestStack
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([['type', 'regular'], ['rootId', 1], ['id', 9]]);

        $request = new Request();
        $request->attributes->set('pageModel', $page);

        $requests = new RequestStack();
        $requests->push($request);

        return $requests;
    }

    private function module(): Module
    {
        $model = $this->createMock(ModuleModel::class);
        $model->method('row')->willReturn(['type' => 'registration']);

        $module = $this->createMock(Module::class);
        $module->method('getModel')->willReturn($model);

        return $module;
    }

    private function user(): FrontendUser
    {
        $user = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = 5;

        return $user;
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 5 : null);

        return $dc;
    }

    /**
     * @return array{groups: list<string>, auto: list<string>, at: int|null}
     */
    private function member(): array
    {
        $row = $this->connection->fetchAssociative('SELECT "groups" AS grp, consho_netGroupsAuto, consho_netGroupsAutoAt FROM tl_member WHERE id = 5');
        self::assertIsArray($row);

        return [
            'groups' => array_values(array_map('strval', StringUtil::deserialize($row['grp'], true))),
            'auto' => array_values(array_map('strval', StringUtil::deserialize($row['consho_netGroupsAuto'], true))),
            'at' => null === $row['consho_netGroupsAutoAt'] ? null : (int) $row['consho_netGroupsAutoAt'],
        ];
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDeclaration;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationKind;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationOutcome;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationResult;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;
use Webwerkwien\ContaoConshoBundle\Cancellation\OperatorCancellationDates;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\SubscriptionCancelBackendController;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The operator's cancellation in the back end (Abo-9, P128): the dates, the
 * lead time and its confirmation, the reason, and what goes to the recorder.
 * Real dates, real template; the recorder has tests of its own.
 */
final class SubscriptionCancelBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<CancellationDeclaration> */
    private array $recorded = [];

    /** @var list<list<int>> */
    private array $offerGroups = [];

    /** @var list<array<string, mixed>> */
    private array $renewalRows = [];

    private bool $contractRenewable = true;

    private int $shortAt = 0;

    private int $keptAt = 0;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY, "groups" TEXT)');
        $this->connection->insert('tl_member', ['id' => 5, $this->connection->quoteSingleIdentifier('groups') => serialize(['3'])]);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'subscriptionOperatorDays' => 56]);

        // A yearly minimum term that ends in about a month: that end is
        // closer than the 56 days, the next one keeps them.
        $calendar = new SubscriptionCalendar();
        $row = ['startedAt' => time() - 330 * 86_400, 'pausedMonths' => 0];
        $this->shortAt = $calendar->boundary($row, 12);
        $this->keptAt = $calendar->boundary($row, 24);

        $this->connection->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00096-1', 'shop' => 1, 'member' => 5, 'product' => 7, 'rule' => 2,
            'state' => 'active', 'phase' => 'minimum', 'renewal' => 'sameTerm', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 12,
            'startedAt' => $row['startedAt'], 'minimumEndsAt' => $this->shortAt, 'pausedMonths' => 0,
        ]);

        require __DIR__.'/../../../contao/languages/de/tl_consho_subscription.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testTermineMitVorlaufVorausgewaehlt(): void
    {
        $html = $this->controller($this->get())($this->dc());

        // The term's end without the lead time and the next one with it.
        self::assertSame(2, substr_count($html, '<option value='));
        self::assertStringContainsString('<option value="'.$this->shortAt.'">', $html);
        self::assertMatchesRegularExpression('~<option value="'.$this->shortAt.'">[^<]*ohne den Vorlauf~', $html);
        self::assertStringContainsString('<option value="'.$this->keptAt.'" selected>', $html);
        self::assertStringContainsString('name="consho_cancel_short"', $html);
        self::assertStringContainsString('56 Tagen Vorlauf', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
    }

    public function testKuendigenMitGrund(): void
    {
        try {
            $this->controller($this->post($this->keptAt, 'Das Modul wird eingestellt.'))($this->dc());
            self::fail('POST leitet um');
        } catch (RedirectResponseException) {
        }

        self::assertCount(1, $this->recorded);
        $declaration = $this->recorded[0];
        self::assertSame(CancellationSource::Operator, $declaration->source);
        self::assertSame(CancellationKind::Ordinary, $declaration->kind);
        self::assertSame($this->keptAt, $declaration->requestedAt);
        self::assertSame('Das Modul wird eingestellt.', $declaration->reason);
        self::assertSame('k.admin', $declaration->name);
        self::assertTrue($declaration->renewalHint, 'die Regel ist noch im Angebot');
        self::assertSame([[3]], $this->offerGroups, 'mit den Gruppen des Kunden von heute');
        self::assertSame($this->keptAt, $this->renewalRows[0]['endsAt'], 'die Vertragsseite mit dem gesetzten Ende');
        self::assertSame('WEB-00096-1', $this->renewalRows[0]['subscriptionNumber']);
        self::assertSame('addConfirmation', $this->messages[0][0]);
        self::assertStringContainsString('WEB-00096-1-K1', $this->messages[0][1]);
    }

    /**
     * A resting, paused, overdue or renewed contract has no "Lizenz
     * verlängern" under "Meine Abos" — the mail must not promise it.
     */
    public function testKeinVerlaengerungshinweisWennDerVertragNichtVerlaengerbarIst(): void
    {
        $this->contractRenewable = false;

        try {
            $this->controller($this->post($this->keptAt, 'Grund'))($this->dc());
        } catch (RedirectResponseException) {
        }

        self::assertFalse($this->lastRecorded()?->renewalHint);
        self::assertSame([], $this->offerGroups, 'die Angebotsseite wird gar nicht erst gefragt');
    }

    /**
     * A member without groups (NULL in MySQL) and one that no longer exists.
     */
    public function testMitgliedOhneGruppenOderGeloescht(): void
    {
        $this->connection->update('tl_member', [$this->connection->quoteSingleIdentifier('groups') => null], ['id' => 5]);

        try {
            $this->controller($this->post($this->keptAt, 'Grund'))($this->dc());
        } catch (RedirectResponseException) {
        }

        $withoutGroups = $this->offerGroups;

        $this->connection->delete('tl_member', ['id' => 5]);

        try {
            $this->controller($this->post($this->keptAt, 'Grund'))($this->dc());
        } catch (RedirectResponseException) {
        }

        self::assertSame([[]], $withoutGroups);
        self::assertFalse($this->lastRecorded()?->renewalHint);
        self::assertSame($withoutGroups, $this->offerGroups, 'ohne Mitglied keine Angebotsfrage');
    }

    public function testOhneShopZeileDieVorgabe(): void
    {
        $this->connection->delete('tl_consho_shop', ['id' => 1]);

        self::assertStringContainsString('56 Tagen Vorlauf', $this->controller($this->get())($this->dc()));
    }

    public function testBeendetesAboNichtKuendbar(): void
    {
        $this->connection->update('tl_consho_subscription', ['endsAt' => time() - 86_400, 'state' => 'ended'], ['id' => 1]);

        $html = $this->controller($this->get())($this->dc());

        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('lässt sich nicht kündigen', $html);
    }

    public function testOhneVorlaufNurMitBestaetigung(): void
    {
        $html = $this->controller($this->post($this->shortAt, 'Kurzfristig.'))($this->dc());

        self::assertNull($this->lastRecorded());
        self::assertSame('addError', $this->messages[0][0]);
        self::assertStringContainsString('Kurzfristig.</textarea>', $html, 'der Grund bleibt stehen');
        self::assertStringContainsString('method="post" class="tl_form" data-turbo="false"', $html, 'Turbo drops a POST answered without a redirect');
        self::assertStringContainsString('<option value="'.$this->shortAt.'" selected>', $html);

        try {
            $this->controller($this->post($this->shortAt, 'Kurzfristig.', true))($this->dc());
            self::fail('POST leitet um');
        } catch (RedirectResponseException) {
        }

        self::assertSame($this->shortAt, $this->lastRecorded()?->requestedAt);
    }

    public function testOhneGrundOderMitFremdemTerminNichts(): void
    {
        $this->controller($this->post($this->keptAt, '   '))($this->dc());
        $this->controller($this->post($this->keptAt + 86_400, 'Grund'))($this->dc());

        self::assertSame([], $this->recorded);
        self::assertSame(['addError', 'addError'], array_column($this->messages, 0));
        self::assertStringContainsString('Grund', $this->messages[0][1]);
    }

    public function testMailNichtHinausGemeldet(): void
    {
        try {
            $this->controller($this->post($this->keptAt, 'Grund'), confirmed: false)($this->dc());
        } catch (RedirectResponseException) {
        }

        self::assertSame(['addConfirmation', 'addError'], array_column($this->messages, 0));
    }

    public function testMitBekanntemEndeKeinFormular(): void
    {
        $this->connection->update('tl_consho_subscription', ['endsAt' => $this->shortAt], ['id' => 1]);

        $html = $this->controller($this->get())($this->dc());

        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('Das Abo endet bereits am', $html);
    }

    public function testOhneRechtNichts(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller($this->get(), granted: false)($this->dc());
    }

    private function lastRecorded(): CancellationDeclaration|null
    {
        return $this->recorded[array_key_last($this->recorded) ?? -1] ?? null;
    }

    private function get(): Request
    {
        return Request::create('/contao?do=consho_subscription&key=cancelSubscription&id=1');
    }

    private function post(int $at, string $reason, bool $short = false): Request
    {
        return Request::create('/contao?do=consho_subscription&key=cancelSubscription&id=1', 'POST', [
            'consho_cancel_at' => (string) $at,
            'consho_cancel_reason' => $reason,
        ] + ($short ? ['consho_cancel_short' => '1'] : []));
    }

    private function controller(Request $request, bool $granted = true, bool $confirmed = true): SubscriptionCancelBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute, mixed $subject = null): bool => $granted
            && ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'consho_subscription' === $subject);
        $security->method('getUser')->willReturn(new InMemoryUser('k.admin', null));

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            if ('generate' !== $name) {
                $messages[] = [$name, (string) $args[0]];
            }

            return '';
        });
        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_subscription');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $message : $system);

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);

        $recorder = $this->createMock(CancellationRecorder::class);
        $recorder->method('record')->willReturnCallback(function (CancellationDeclaration $declaration) use ($confirmed): CancellationResult {
            $this->recorded[] = $declaration;

            return new CancellationResult(CancellationOutcome::Recorded, 9, 'WEB-00096-1-K1', 'WEB-00096-1', time(), $declaration->requestedAt, confirmed: $confirmed);
        });

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('allows')->willReturnCallback(function (int $article, int $rule, array $groups): bool {
            $this->offerGroups[] = $groups;

            return 7 === $article && 2 === $rule;
        });

        $renewal = $this->createMock(SubscriptionRenewal::class);
        $renewal->method('renewable')->willReturnCallback(function (array $row): bool {
            $this->renewalRows[] = $row;

            return $this->contractRenewable;
        });

        return new SubscriptionCancelBackendController($this->connection, $security, $framework, $requests, $twig, new OperatorCancellationDates(new SubscriptionCalendar()), $recorder, $offers, $renewal);
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => 1,
            'table' => 'tl_consho_subscription',
            default => null,
        });

        return $dc;
    }
}

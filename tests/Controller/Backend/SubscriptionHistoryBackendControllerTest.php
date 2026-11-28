<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\SubscriptionHistoryBackendController;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The history of a subscription: cancellations and pauses on one page,
 * newest first, each with the way to its own record. Real template.
 */
final class SubscriptionHistoryBackendControllerTest extends TestCase
{
    private Connection $connection;

    private string $table = 'tl_consho_subscription';

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_cancellation');
        DcaSchema::create($this->connection, 'tl_consho_subscription_pause');
        DcaSchema::create($this->connection, 'tl_consho_subscription_price');

        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-00096-1']);
        $this->connection->insert('tl_consho_subscription', ['id' => 2, 'subscriptionNumber' => 'WEB-00097-1']);

        $GLOBALS['TL_LANGUAGE'] = 'de';
        require __DIR__.'/../../../contao/languages/de/tl_consho_subscription.php';
        require __DIR__.'/../../../contao/languages/de/tl_consho_cancellation.php';
        require __DIR__.'/../../../contao/languages/de/tl_consho_subscription_pause.php';
        require __DIR__.'/../../../contao/languages/de/tl_consho_subscription_price.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testKuendigungenUndPausenGemischtNeuesteZuerst(): void
    {
        $this->cancellation(11, strtotime('2026-09-01 10:00'), ['number' => 'K-0001', 'state' => 'rejected', 'decidedAt' => strtotime('2026-09-02'), 'decisionSentAt' => strtotime('2026-09-02')]);
        $this->cancellation(12, strtotime('2026-10-20 09:00'), ['number' => 'K-0002', 'state' => 'effective', 'effectiveAt' => strtotime('2027-03-31')]);
        $this->pause(21, strtotime('2026-10-01 08:00'), ['months' => 2, 'source' => 'operator', 'withdrawnAt' => strtotime('2026-10-05')]);
        // Another subscription's entries stay out.
        $this->cancellation(13, strtotime('2026-10-25'), ['pid' => 2, 'number' => 'K-0003']);

        $html = $this->controller()($this->dc());

        self::assertStringContainsString('WEB-00096-1', $html);
        self::assertStringNotContainsString('K-0003', $html);
        self::assertLessThan(strpos($html, 'zurückgenommen am'), strpos($html, 'K-0002'));
        self::assertLessThan(strpos($html, 'K-0001'), strpos($html, 'zurückgenommen am'));
        self::assertStringContainsString('2 Monat(e)', $html);
        self::assertStringContainsString('wirksam zum', $html);

        // The ways to the records, in the module.
        self::assertStringContainsString('table=tl_consho_cancellation&amp;act=edit&amp;id=12', $html);
        self::assertStringContainsString('table=tl_consho_cancellation&amp;act=show&amp;id=11', $html);
        self::assertStringContainsString('table=tl_consho_subscription_pause&amp;act=show&amp;id=21', $html);
        self::assertStringNotContainsString('table=tl_consho_subscription_pause&amp;act=edit', $html);
        self::assertStringNotContainsString('resendDecision', $html, 'nothing to resend: undecided, or the mail left');
    }

    /**
     * As in the cancellation list: decided, but the mail did not leave.
     */
    public function testEntscheidungErneutSendenNurWennDieMailFehlt(): void
    {
        $this->cancellation(11, strtotime('2026-09-01'), ['state' => 'rejected', 'decidedAt' => strtotime('2026-09-02')]);

        $html = $this->controller()($this->dc());

        self::assertStringContainsString('<form action="/contao?do=consho_subscription&amp;table=tl_consho_cancellation&amp;key=resendDecision&amp;id=11" method="post">', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
        self::assertStringContainsString('alt="Entscheidung erneut senden"', $html);
    }

    /**
     * Abo-9: who cancelled — above all the operator — stands with the
     * cancellation, as it does with a pause.
     */
    public function testDieQuelleDerKuendigung(): void
    {
        $this->cancellation(11, strtotime('2026-09-01'), ['source' => 'operator', 'state' => 'effective', 'effectiveAt' => strtotime('2027-06-30')]);

        self::assertStringContainsString('ordentlich · Betreiber (Backend) · wirksam zum', html_entity_decode($this->controller()($this->dc())));
    }

    /**
     * Abo-9: a later cancellation that ends the contract earlier
     * supersedes the earlier one (P134); its old date stays, marked. A later
     * one that is rejected or still waiting supersedes nothing.
     */
    public function testEineUeberholteKuendigungZeigtWodurch(): void
    {
        $this->cancellation(11, strtotime('2026-09-01'), ['number' => 'K-0001', 'source' => 'operator', 'state' => 'effective', 'effectiveAt' => strtotime('2027-06-30')]);
        $this->cancellation(12, strtotime('2026-09-10'), ['number' => 'K-0002', 'kind' => 'extraordinary', 'state' => 'rejected', 'effectiveAt' => strtotime('2026-10-31')]);
        $this->cancellation(13, strtotime('2026-09-20'), ['number' => 'K-0003', 'source' => 'account', 'state' => 'effective', 'effectiveAt' => strtotime('2027-03-31')]);

        $html = html_entity_decode($this->controller()($this->dc()));

        self::assertSame(1, substr_count($html, 'abgelöst durch'));
        self::assertStringContainsString('wirksam · abgelöst durch K-0003', $html);
        // The mark sits in K-0001's row, not in the newest one's.
        self::assertGreaterThan(strpos($html, 'K-0001'), strpos($html, 'abgelöst durch'));
    }

    /**
     * What counts is the earlier end, not the order of receipt: an
     * extraordinary cancellation accepted at an earlier date supersedes one
     * received after it as well as those before. Of two earlier ends, the
     * earliest is named.
     */
    public function testDasFruehereEndeZaehltNichtDerEingang(): void
    {
        $this->cancellation(11, strtotime('2026-09-15'), ['number' => 'K-0001', 'kind' => 'extraordinary', 'state' => 'accepted', 'decidedAt' => strtotime('2026-09-25'), 'decisionSentAt' => strtotime('2026-09-25'), 'effectiveAt' => strtotime('2026-10-31')]);
        $this->cancellation(12, strtotime('2026-09-10'), ['number' => 'K-0002', 'state' => 'effective', 'effectiveAt' => strtotime('2027-03-31')]);
        $this->cancellation(13, strtotime('2026-09-05'), ['number' => 'K-0003', 'source' => 'operator', 'state' => 'effective', 'effectiveAt' => strtotime('2027-06-30')]);
        $this->cancellation(14, strtotime('2026-09-20'), ['number' => 'K-0004', 'kind' => 'extraordinary', 'state' => 'accepted', 'decidedAt' => strtotime('2026-09-22'), 'decisionSentAt' => strtotime('2026-09-22'), 'effectiveAt' => strtotime('2027-09-30')]);

        $html = html_entity_decode($this->controller()($this->dc()));

        self::assertSame(3, substr_count($html, 'abgelöst durch K-0001'));
        self::assertStringNotContainsString('abgelöst durch K-0002', $html);
        self::assertStringContainsString('angenommen</td>', $html, 'the one in force carries no mark');
    }

    public function testOhneEintraegeEinHinweis(): void
    {
        self::assertStringContainsString('keine Kündigung, keine Pause und kein Preisangebot', $this->controller()($this->dc()));
    }

    /**
     * Abo-10 (P130): price offers among the rest, only to show.
     */
    public function testPreisangeboteImVerlauf(): void
    {
        $this->cancellation(11, strtotime('2026-09-01 10:00'), ['number' => 'K-0001']);
        $this->connection->insert('tl_consho_subscription_price', [
            'id' => 31, 'pid' => 1, 'createdAt' => strtotime('2026-10-01 08:00'), 'effectiveAt' => strtotime('2027-01-31'), 'deadlineAt' => strtotime('2027-01-17'),
            'oldUnitNet' => '10.0000', 'newUnitNet' => '12.5000', 'currency' => 'EUR', 'state' => 'accepted', 'answeredBy' => 'account', 'appliedAt' => strtotime('2027-01-31'),
        ]);
        $this->connection->insert('tl_consho_subscription_price', ['id' => 32, 'pid' => 2, 'createdAt' => 5, 'effectiveAt' => 5, 'oldUnitNet' => '1.0000', 'newUnitNet' => '99.0000', 'currency' => 'EUR']);

        $html = html_entity_decode($this->controller()($this->dc()));

        self::assertStringContainsString('Preisangebot', $html);
        self::assertMatchesRegularExpression('/10,00\s€ → 12,50\s€/u', $html);
        self::assertStringContainsString('gültig ab 31. Januar 2027 · Antwort bis 17. Januar 2027', $html);
        self::assertStringContainsString('angenommen · Kunde („Meine Abos") · übernommen am 31. Januar 2027', $html);
        self::assertStringNotContainsString('99,00', $html);
        self::assertLessThan(strpos($html, 'K-0001'), strpos($html, 'Preisangebot'), 'newest first');
        self::assertStringContainsString('table=tl_consho_subscription_price&act=show&id=31', $html);
    }

    /**
     * In a child table the id is a cancellation or a pause.
     */
    public function testNurInDerAbotabelle(): void
    {
        $this->table = 'tl_consho_cancellation';

        $this->expectException(NotFoundHttpException::class);

        $this->controller()($this->dc());
    }

    public function testOhneRechtNichts(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(false)($this->dc());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function cancellation(int $id, int $receivedAt, array $values = []): void
    {
        $this->connection->insert('tl_consho_cancellation', $values + ['id' => $id, 'pid' => 1, 'number' => 'K-'.$id, 'receivedAt' => $receivedAt, 'kind' => 'ordinary', 'state' => 'received']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function pause(int $id, int $requestedAt, array $values = []): void
    {
        $this->connection->insert('tl_consho_subscription_pause', $values + [
            'id' => $id, 'pid' => 1, 'months' => 1, 'pauseFrom' => strtotime('2026-11-01'), 'pauseUntil' => strtotime('2027-01-01'),
            'source' => 'account', 'requestedAt' => $requestedAt,
        ]);
    }

    private function controller(bool $granted = true): SubscriptionHistoryBackendController
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute, mixed $subject = null): bool => $granted
            && ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'consho_subscription' === $subject);

        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturn('');
        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_subscription');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $message : $system);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $params): string => '/contao?'.http_build_query($params));

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);
        $twig->addFunction(new TwigFunction('backend_icon', static fn (string $src, string $alt = ''): string => '<img src="'.$src.'" alt="'.htmlspecialchars($alt).'">', ['is_safe' => ['html']]));

        return new SubscriptionHistoryBackendController($this->connection, $security, $framework, $router, $twig);
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $table = $this->table;
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => 1,
            'table' => $table,
            default => null,
        });

        return $dc;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Mail\StartPointDomainIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-4: The link in the mail from the command pointed to localhost —
 * the start point has no domain, and on the command line there is no
 * request the host could come from.
 */
final class StartPointDomainIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::createCore($this->connection, 'tl_page');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
    }

    public function testDerFallAusAbo4(): void
    {
        $this->root(1, 'Test-Startpunkt', '');
        $this->root(1, 'Test-Startpunkt FR', '');

        $issues = $this->source()->issues();

        self::assertCount(1, $issues, 'ein Befund je Shop, nicht je Startpunkt');
        self::assertSame(ConfigurationFinding::StartPointWithoutDomain, $issues[0]->finding);
        self::assertSame(['shop' => 'Testshop', 'roots' => 'Test-Startpunkt, Test-Startpunkt FR'], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    public function testStartpunktMitDomainKeinBefund(): void
    {
        $this->root(1, 'Test-Startpunkt', 'shop.example.org');

        self::assertSame([], $this->source()->issues());
    }

    public function testNurDieStartpunkteOhneDomainWerdenGenannt(): void
    {
        $this->root(1, 'Mit Domain', 'shop.example.org');
        $this->root(1, 'Ohne Domain', '');

        self::assertSame('Ohne Domain', $this->source()->issues()[0]->context['roots']);
    }

    public function testStartpunktOhneShopZähltNicht(): void
    {
        $this->root(0, 'Contao Official Demo', '');

        self::assertSame([], $this->source()->issues());
    }

    public function testNurStartpunkte(): void
    {
        $this->connection->insert('tl_page', ['type' => 'regular', 'title' => 'Seite', 'consho_shop' => 1]);

        self::assertSame([], $this->source()->issues());
    }

    public function testDefaultUriSchweigtDenBefund(): void
    {
        $this->root(1, 'Test-Startpunkt', '');

        self::assertSame([], $this->source('https://shop.example.org')->issues(), 'framework.router.default_uri');
    }

    public function testEigenerHostImRequestContextSchweigtDenBefund(): void
    {
        $this->root(1, 'Test-Startpunkt', '');

        self::assertSame([], $this->source('', 'shop.example.org')->issues(), 'router.request_context.host, der ältere Weg');
    }

    public function testBasisPfadOhneHostIstKeineDomain(): void
    {
        $this->root(1, 'Test-Startpunkt', '');

        self::assertCount(1, $this->source('/shop')->issues(), 'ein base_url ohne Host liefert weiter localhost');
    }

    private function source(string $baseUrl = '', string $host = 'localhost'): StartPointDomainIssueSource
    {
        return new StartPointDomainIssueSource($this->connection, $baseUrl, $host);
    }

    private function root(int $shop, string $title, string $dns): void
    {
        $this->connection->insert('tl_page', ['type' => 'root', 'title' => $title, 'dns' => $dns, 'consho_shop' => $shop]);
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Mail\SenderDomainIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * D-7, M5: Sender domain against the domains of the start points, without DNS.
 */
final class SenderDomainIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_mail_transport');
        DcaSchema::createCore($this->connection, 'tl_page');

        $this->connection->insert('tl_consho_mail_transport', ['id' => 1, 'senderAddress' => 'web@example.net']);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'mailTransport' => 1]);
    }

    public function testDerFallAusD1(): void
    {
        $this->root(1, 'shop.example.org');

        $issues = (new SenderDomainIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::SenderDomainMismatch, $issues[0]->finding);
        self::assertSame(['shop' => 'Testshop', 'sender' => 'example.net', 'domains' => 'shop.example.org'], $issues[0]->context);
        self::assertFalse($issues[0]->finding->isWarning());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function passende(): iterable
    {
        yield 'gleich' => ['example.net'];
        yield 'Shop unter einer Subdomain' => ['shop.example.net'];
        yield 'Groß geschrieben' => ['EXAMPLE.NET'];
    }

    /**
     * @dataProvider passende
     */
    public function testPassendeDomainKeinBefund(string $dns): void
    {
        $this->root(1, 'shop.example.org');
        $this->root(1, $dns);

        self::assertSame([], (new SenderDomainIssueSource($this->connection))->issues(), 'ein passender Startpunkt genügt');
    }

    public function testAbsenderUnterEinerSubdomainDesShops(): void
    {
        $this->connection->update('tl_consho_mail_transport', ['senderAddress' => 'shop@mail.example.net'], ['id' => 1]);
        $this->root(1, 'example.net');

        self::assertSame([], (new SenderDomainIssueSource($this->connection))->issues());
    }

    public function testUmlautDomainWieContaoSieSpeichert(): void
    {
        $this->connection->update('tl_consho_mail_transport', ['senderAddress' => 'shop@müller.at'], ['id' => 1]);
        $this->root(1, 'xn--mller-kva.at');

        self::assertSame([], (new SenderDomainIssueSource($this->connection))->issues(), 'tl_page.dns steht als Punycode');
    }

    public function testNurDieEndungGleichIstKeinePassung(): void
    {
        $this->root(1, 'boeseexample.net');

        self::assertCount(1, (new SenderDomainIssueSource($this->connection))->issues());
    }

    public function testStartpunkteOhneDomainSagenNichts(): void
    {
        $this->root(1, '');
        $this->root(2, 'shop.example.org');

        self::assertSame([], (new SenderDomainIssueSource($this->connection))->issues(), 'leer antwortet für jeden Host; Startpunkt eines anderen Shops zählt nicht');
    }

    private function root(int $shop, string $dns): void
    {
        $this->connection->insert('tl_page', ['type' => 'root', 'dns' => $dns, 'consho_shop' => $shop]);
    }
}

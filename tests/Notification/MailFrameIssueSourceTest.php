<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Notification\MailFrameIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * GARAN part 2 (L4, L5): mail footer empty, logo too heavy for mails.
 */
class MailFrameIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    public function testFusszeileLeerNurWennWederFirmaNochFusszeile(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Leer', 'invoiceFooter' => '<p> </p>']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Firma', 'invoiceCompany' => 'Muster GmbH']);
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'title' => 'Fußzeile', 'invoiceFooter' => 'Max Muster, Wien']);

        $issues = (new MailFrameIssueSource($this->connection))->issues();

        self::assertSame([[ConfigurationFinding::MailFooterEmpty, ['shop' => 'Leer']]], array_map(static fn (ConfigurationIssue $issue): array => [$issue->finding, $issue->context], $issues));
    }

    public function testLogoUeberHundertKilobyte(): void
    {
        $small = str_repeat("\x01", 16);
        $large = str_repeat("\x02", 16);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Klein', 'invoiceCompany' => 'A', 'invoiceLogo' => $small]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Groß', 'invoiceCompany' => 'B', 'invoiceLogo' => $large]);

        $files = $this->createStub(PublicFiles::class);
        $files->method('size')->willReturnCallback(static fn (string $uuid): int => $uuid === $large ? 150 * 1024 : PublicFiles::MAIL_IMAGE_MAX_BYTES);

        $issues = (new MailFrameIssueSource($this->connection, $files))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::MailLogoTooLarge, $issues[0]->finding);
        self::assertSame(['shop' => 'Groß', 'size' => '150'], $issues[0]->context);
    }
}

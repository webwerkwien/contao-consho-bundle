<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoConshoBundle\Command\UrlPruneCommand;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectPruner;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class UrlPruneCommandTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($this->connection, 'tl_consho_url_redirect');

        $this->recorded('/produkt/alt.html', strtotime('-2 years'));
        $this->recorded('/produkt/aelter.html', strtotime('-100 days'));
        $this->recorded('/produkt/frisch.html', time());
    }

    public function testReportsWithoutDeleting(): void
    {
        /*
         * The default. What this command removes are the redirects that keep
         * old links and search results alive — a command that deletes of its
         * own accord gets run by accident exactly once.
         */
        $tester = $this->execute(['--older-than' => '90 days']);

        self::assertStringContainsString('2 address(es)', $tester->getDisplay());
        self::assertStringContainsString('Nothing was deleted', $tester->getDisplay());
        self::assertSame(3, $this->remaining());
    }

    public function testDeletesWithForce(): void
    {
        $tester = $this->execute(['--older-than' => '90 days', '--force' => true]);

        self::assertStringContainsString('2 superseded address(es) removed', $tester->getDisplay());
        self::assertSame(1, $this->remaining());
    }

    public function testRefusesWithoutAnAge(): void
    {
        // No default retention: when an address may stop working depends on the
        // shop's backlinks, and a period built in is one that surprises
        // somebody eventually.
        $tester = $this->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('deliberately no default', $tester->getDisplay());
        self::assertSame(3, $this->remaining());
    }

    public function testRefusesAnAgeItCannotRead(): void
    {
        $tester = $this->execute(['--older-than' => 'irgendwann']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(3, $this->remaining());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function agesStrtotimeMisreads(): iterable
    {
        // "y" is a military time zone to strtotime(): "-10y" gave a cut-off
        // in the future, and --force removed every address.
        yield 'unit as a time zone' => ['10y'];
        yield 'no count' => ['days'];
        yield 'zero' => ['0 days'];
        yield 'a date' => ['2020-01-01'];
        yield 'a word' => ['tomorrow'];
        yield 'two parts' => ['1 year 2 days'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agesStrtotimeMisreads')]
    public function testRefusesWhatIsNoCountAndUnit(string $age): void
    {
        $tester = $this->execute(['--older-than' => $age, '--force' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(3, $this->remaining(), 'nothing removed');
    }

    public function testReadsTheUnitsItOffers(): void
    {
        foreach (['1 year', '2 YEARS', '100days', '3 months', '20 weeks'] as $age) {
            self::assertSame(Command::SUCCESS, $this->execute(['--older-than' => $age])->getStatusCode(), $age);
        }
    }

    public function testSaysSoWhenThereIsNothingToRemove(): void
    {
        $tester = $this->execute(['--older-than' => '10 years', '--force' => true]);

        self::assertStringContainsString('No superseded address', $tester->getDisplay());
        self::assertSame(3, $this->remaining());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester(new UrlPruneCommand(new RedirectPruner($this->connection)));
        $tester->execute($input);

        return $tester;
    }

    private function remaining(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_url_redirect');
    }

    private function recorded(string $path, int $createdAt): void
    {
        $this->connection->insert('tl_consho_url_redirect', [
            'pid' => 1,
            'oldPath' => $path,
            'productId' => 10,
            'createdAt' => $createdAt,
        ]);
    }
}

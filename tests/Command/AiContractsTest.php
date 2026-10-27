<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Command\BillSubscriptionsCommand;
use Webwerkwien\ContaoConshoBundle\Command\CatalogDiagnoseCommand;
use Webwerkwien\ContaoConshoBundle\Command\CatalogVerifyCommand;
use Webwerkwien\ContaoConshoBundle\Command\CheckCommand;
use Webwerkwien\ContaoConshoBundle\Command\DevBenchmarkCommand;
use Webwerkwien\ContaoConshoBundle\Command\DevSeedCommand;
use Webwerkwien\ContaoConshoBundle\Command\DiagnoseTaxCommand;
use Webwerkwien\ContaoConshoBundle\Command\DocumentVerifyCommand;
use Webwerkwien\ContaoConshoBundle\Command\PriceRebuildCommand;
use Webwerkwien\ContaoConshoBundle\Command\PriceVerifyCommand;
use Webwerkwien\ContaoConshoBundle\Command\RunSubscriptionsCommand;
use Webwerkwien\ContaoConshoBundle\Command\SoldOutVerifyCommand;
use Webwerkwien\ContaoConshoBundle\Command\UrlPruneCommand;
use Webwerkwien\ContaoConshoBundle\Command\VisibilityRebuildCommand;
use Webwerkwien\ContaoConshoBundle\Command\VisibilityVerifyCommand;

/**
 * Which core commands contao-ai may start (CLI coverage E1): the
 * maintenance commands yes, the developer commands and the subscription
 * billing no, `consho:check` no – that is covered by
 * `consho:connect:findings` (Connect C1).
 *
 * Read via getArguments(), not newInstance(): the core does not depend on
 * contao-ai, the attribute class does not exist here – and that is exactly
 * how contao-ai reads the contract too.
 */
final class AiContractsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, bool}>
     */
    public static function mitVertrag(): iterable
    {
        yield 'catalog:diagnose' => [CatalogDiagnoseCommand::class, false];
        yield 'catalog:verify' => [CatalogVerifyCommand::class, false];
        yield 'price:verify' => [PriceVerifyCommand::class, false];
        yield 'price:rebuild' => [PriceRebuildCommand::class, true];
        yield 'visibility:verify' => [VisibilityVerifyCommand::class, false];
        yield 'visibility:rebuild' => [VisibilityRebuildCommand::class, true];
        yield 'sold-out:verify' => [SoldOutVerifyCommand::class, true];
        yield 'tax:diagnose' => [DiagnoseTaxCommand::class, false];
        yield 'document:verify' => [DocumentVerifyCommand::class, true];
        yield 'url:prune' => [UrlPruneCommand::class, true];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('mitVertrag')]
    public function testWartungsbefehleTragenEinenVertrag(string $class, bool $writes): void
    {
        $contract = self::contract($class);

        self::assertNotNull($contract, $class.' trägt keinen Vertrag');
        self::assertSame($writes, $contract['writes'] ?? null);
        self::assertNotEmpty($contract['tables'] ?? [], 'die Tabellen, die er liest oder schreibt');

        // contao-ai's ContractReader reports a writing contract without
        // `trace` as faulty – even if it leaves no trace.
        if ($writes) {
            self::assertArrayHasKey('trace', $contract, 'writes ohne trace');
        }

        // contao-ai checks `tables` against the DCA: a Consho table needs
        // its file here, a Contao table comes with Contao. The projections
        // are in no back-end module – so in the chat only admins see the
        // commands that name them. Intended: maintenance, and the tables are
        // listed because they are written.
        foreach ($contract['tables'] as $table) {
            if (!\in_array($table, ['tl_page', 'tl_files'], true)) {
                self::assertFileExists(__DIR__.'/../../contao/dca/'.$table.'.php', 'Tabelle ohne DCA im Kern: '.$table);
            }
        }
    }

    /**
     * Final are the deletion of the old addresses and catching up on the
     * archiving (`document:verify --retry`: an archived file is never
     * overwritten) – and the contract must say so. Since contao-ai backend
     * v0.22.1 they do not run in the chat.
     */
    public function testNurDasLoeschenDerAdressenUndDasArchivSindEndgueltig(): void
    {
        $endgueltig = [UrlPruneCommand::class, \Webwerkwien\ContaoConshoBundle\Command\DocumentVerifyCommand::class];

        foreach ($endgueltig as $class) {
            self::assertNotEmpty(self::contract($class)['irreversible'] ?? null, $class);
        }

        foreach (self::mitVertrag() as [$class]) {
            if (!\in_array($class, $endgueltig, true)) {
                self::assertArrayNotHasKey('irreversible', self::contract($class) ?? [], $class);
            }
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function ohneVertrag(): iterable
    {
        yield 'check (Connect findings)' => [CheckCommand::class];
        yield 'dev:seed' => [DevSeedCommand::class];
        yield 'dev:benchmark' => [DevBenchmarkCommand::class];
        yield 'subscription:bill' => [BillSubscriptionsCommand::class];
        yield 'subscription:run' => [RunSubscriptionsCommand::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('ohneVertrag')]
    public function testDieseBleibenDerKonsoleVorbehalten(string $class): void
    {
        self::assertNull(self::contract($class));
    }

    /**
     * @param class-string $class
     *
     * @return array<string, mixed>|null
     */
    private static function contract(string $class): array|null
    {
        $attributes = (new \ReflectionClass($class))->getAttributes('Webwerkwien\ContaoAiCoreBundle\Attribute\AiContract');

        return [] === $attributes ? null : $attributes[0]->getArguments();
    }
}

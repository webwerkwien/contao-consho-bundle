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
 * Welche Kern-Befehle contao-ai starten darf (CLI-Abdeckung E1, Michael
 * 2026-10-14: „ja, alles wie empfohlen"): die Wartungsbefehle ja, die
 * Entwicklerbefehle und die Abo-Abrechnung nicht, `consho:check` nicht – den
 * deckt `consho:connect:findings` ab (Connect C1).
 *
 * Gelesen über getArguments(), nicht newInstance(): Der Kern hängt nicht von
 * contao-ai ab, die Attributklasse gibt es hier nicht – genau so liest
 * contao-ai den Vertrag auch.
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

        // contao-ais ContractReader meldet einen schreibenden Vertrag ohne
        // `trace` als fehlerhaft (Review K) – auch wenn er keine Spur hinterlässt.
        if ($writes) {
            self::assertArrayHasKey('trace', $contract, 'writes ohne trace');
        }

        // contao-ai hält `tables` gegen die DCA: eine Consho-Tabelle braucht
        // ihre Datei hier, eine Contao-Tabelle bringt Contao mit. Die
        // Projektionen stehen in keinem Backend-Modul – damit sehen die
        // Befehle, die sie nennen, im Chat nur Admins. Gewollt (Review K):
        // Wartung, und die Tabellen stehen da, weil sie geschrieben werden.
        foreach ($contract['tables'] as $table) {
            if (!\in_array($table, ['tl_page', 'tl_files'], true)) {
                self::assertFileExists(__DIR__.'/../../contao/dca/'.$table.'.php', 'Tabelle ohne DCA im Kern: '.$table);
            }
        }
    }

    /**
     * Endgültig ist nur das Löschen der alten Adressen – und das muss der
     * Vertrag sagen, damit der Chat vorher fragt.
     */
    public function testNurDasLoeschenDerAdressenIstEndgueltig(): void
    {
        self::assertNotEmpty(self::contract(UrlPruneCommand::class)['irreversible'] ?? null);

        foreach (self::mitVertrag() as [$class]) {
            if (UrlPruneCommand::class !== $class) {
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

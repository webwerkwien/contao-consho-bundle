<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionPauseColumnListener;

/**
 * Abnahme „Abo pausieren" (2026-10-03): „Pause bis" zeigte nach dem Ende der
 * Pause weiter das vergangene Datum.
 */
final class SubscriptionPauseColumnListenerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA'] = [];
        include __DIR__.'/../../../contao/dca/tl_consho_subscription.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testNurLaufendeOderAngekuendigtePausen(): void
    {
        $column = array_search('pauseUntil', $GLOBALS['TL_DCA']['tl_consho_subscription']['list']['label']['fields'], true);
        self::assertIsInt($column, 'die Spalte gibt es');

        $args = array_fill(0, $column + 1, 'x');
        $args[$column] = '01.01.2027 08:00';

        self::assertSame('01.01.2027 08:00', $this->label(['pauseUntil' => self::NOW + 60], $args)[$column], 'läuft oder angekündigt');
        self::assertSame('-', $this->label(['pauseUntil' => self::NOW], $args)[$column], 'vorbei');
        self::assertSame('-', $this->label(['pauseUntil' => 0], $args)[$column], 'nie');
        self::assertSame('x', $this->label(['pauseUntil' => 0], $args)[0], 'andere Spalten bleiben');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, mixed>    $args
     *
     * @return array<int, mixed>
     */
    private function label(array $row, array $args): array
    {
        $result = (new SubscriptionPauseColumnListener(static fn (): int => self::NOW))($row, '', $this->createStub(DataContainer::class), $args);
        self::assertIsArray($result);

        return $result;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionListLabelListener;

/**
 * The subscription list: the state as an eye in front, days without the
 * time, and "Paused until" only while a pause runs or is announced — it kept
 * showing the past date after the pause had ended.
 */
final class SubscriptionListLabelListenerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA'] = [];
        include __DIR__.'/../../../contao/dca/tl_consho_subscription.php';
        require __DIR__.'/../../../contao/languages/de/tl_consho_subscription.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testStatusVorneGruenNurWennAktiv(): void
    {
        self::assertSame(0, $this->column('status'), 'ganz vorne');
        self::assertNotContains('state', $GLOBALS['TL_DCA']['tl_consho_subscription']['list']['label']['fields'], 'keine Spalte Zustand mehr');

        $active = $this->label(['state' => 'active'])[0];
        self::assertStringContainsString('consho-signal--visible consho-signal--done', $active);
        self::assertStringContainsString('title="aktiv"', $active);

        foreach (['suspended' => 'ruhend', 'paused' => 'pausiert', 'cancelled' => 'gekündigt', 'ended' => 'beendet'] as $state => $word) {
            $cell = $this->label(['state' => $state])[0];
            self::assertStringContainsString('consho-signal--visible consho-signal--open', $cell, $state);
            self::assertStringContainsString('title="'.$word.'"', $cell, $state);
        }
    }

    public function testDatenOhneUhrzeit(): void
    {
        $started = (int) strtotime('2026-04-03 08:01');
        $row = ['state' => 'active', 'startedAt' => $started, 'periodUntil' => $started, 'nextBillingAt' => $started, 'endsAt' => null];
        $cells = $this->label($row);

        foreach (['startedAt', 'periodUntil', 'nextBillingAt'] as $field) {
            self::assertSame('<span class="consho-nowrap">03.04.2026</span>', $cells[$this->column($field)], $field);
        }

        self::assertSame('-', $cells[$this->column('endsAt')], 'kein Ende');
    }

    public function testNurLaufendeOderAngekuendigtePausen(): void
    {
        $column = $this->column('pauseUntil');

        self::assertSame('<span class="consho-nowrap">'.date('d.m.Y', self::NOW + 86_400).'</span>', $this->label(['pauseUntil' => self::NOW + 86_400])[$column], 'läuft oder angekündigt');
        self::assertSame('-', $this->label(['pauseUntil' => self::NOW])[$column], 'vorbei');
        self::assertSame('-', $this->label(['pauseUntil' => 0])[$column], 'nie');
        self::assertSame('x', $this->label(['pauseUntil' => 0])[$this->column('subscriptionNumber')], 'andere Spalten bleiben');
    }

    private function column(string $field): int
    {
        $column = array_search($field, $GLOBALS['TL_DCA']['tl_consho_subscription']['list']['label']['fields'], true);
        self::assertIsInt($column, $field);

        return $column;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, mixed>
     */
    private function label(array $row): array
    {
        $args = array_fill(0, \count($GLOBALS['TL_DCA']['tl_consho_subscription']['list']['label']['fields']), 'x');
        $result = (new SubscriptionListLabelListener(static fn (): int => self::NOW))($row, '', $this->createStub(DataContainer::class), $args);
        self::assertIsArray($result);

        return $result;
    }
}

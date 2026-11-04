<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PaymentRefundButtonListener;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;

/**
 * Block Zahlung Mollie, 4.4: when "Erstatten", "Erneut auszahlen" and "Von
 * Hand erledigt" appear at a payment. Hidden means, in Contao: HTML ''.
 */
final class PaymentRefundButtonListenerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testErstattenAnJederZahlungMitRest(): void
    {
        $payout = $this->createMock(RefundPayout::class);
        $payout->method('remainder')->willReturnCallback(static fn (int $orderId, int $id): string|null => \in_array($id, [5, 7], true) ? '30.0000' : null);
        $listener = new PaymentRefundButtonListener($payout);

        $shown = fn (array $record): bool => $this->shown(static fn (DataContainerOperation $o) => $listener->refund($o), $record);

        self::assertTrue($shown(['id' => 5, 'pid' => 1, 'amount' => '30.0000', 'attempt' => 10, 'reference' => 'tr_1']));
        self::assertTrue($shown(['id' => 7, 'pid' => 1, 'amount' => '30.0000', 'attempt' => null, 'reference' => 'Bar']), 'von Hand erfasst: auch');
        self::assertFalse($shown(['id' => 6, 'pid' => 1, 'amount' => '30.0000', 'attempt' => 11, 'reference' => 'tr_2']), 'schon ganz zurückgezahlt');
        self::assertFalse($shown(['id' => 5, 'pid' => 1, 'amount' => '-10.0000', 'attempt' => null, 'reference' => 'tr_1']), 'eine Erstattung');
        self::assertFalse($shown(['id' => 5, 'pid' => 1, 'amount' => '40.0000', 'refundOf' => 3]), 'eine aufgehobene Rückbuchung');
    }

    public function testKorrigierenNurAnEinerGesperrtenZahlungMitRest(): void
    {
        $payout = $this->createMock(RefundPayout::class);
        $payout->method('correctable')->willReturnCallback(static fn (int $id): string|null => 5 === $id ? '30.0000' : null);
        $listener = new PaymentRefundButtonListener($payout);

        $shown = fn (array $record): bool => $this->shown(static fn (DataContainerOperation $o) => $listener->correct($o), $record);

        self::assertTrue($shown(['id' => 5, 'amount' => '30.0000', 'lockedAt' => 100]));
        self::assertFalse($shown(['id' => 5, 'amount' => '30.0000', 'lockedAt' => null]), 'ungesperrt: bearbeiten');
        self::assertFalse($shown(['id' => 5, 'amount' => '-10.0000', 'lockedAt' => 100]), 'eine Erstattung');
        self::assertFalse($shown(['id' => 6, 'amount' => '30.0000', 'lockedAt' => 100]), 'nichts mehr übrig');
    }

    public function testErneutAuszahlenNurNachEinerAblehnung(): void
    {
        $listener = new PaymentRefundButtonListener($this->createStub(RefundPayout::class));
        $shown = fn (string $state): bool => $this->shown(static fn (DataContainerOperation $o) => $listener->retry($o), ['payoutState' => $state]);

        self::assertTrue($shown('failed'));

        foreach (['', 'requested', 'sending', 'done', 'manual'] as $state) {
            self::assertFalse($shown($state), $state);
        }
    }

    public function testVonHandErledigtWoEsWartetOderScheiterte(): void
    {
        $listener = new PaymentRefundButtonListener($this->createStub(RefundPayout::class), static fn (): int => self::NOW);
        $shown = fn (string $state, int $tstamp = self::NOW): bool => $this->shown(static fn (DataContainerOperation $o) => $listener->manual($o), ['payoutState' => $state, 'tstamp' => $tstamp]);

        self::assertTrue($shown('failed'));
        self::assertTrue($shown('requested'));
        self::assertTrue($shown('sending', self::NOW - 7200), 'hängt');
        self::assertFalse($shown('sending', self::NOW - 10), 'die Anfrage läuft noch');
        self::assertFalse($shown('done'));
        self::assertFalse($shown('manual'));
        self::assertFalse($shown(''));
    }

    /**
     * @param \Closure(DataContainerOperation): void $callback
     * @param array<string, mixed>                   $record
     */
    private function shown(\Closure $callback, array $record): bool
    {
        $operation = new DataContainerOperation('op', ['href' => 'key=op', 'icon' => 'ok.svg', 'label' => ['op', '%s']], ['id' => 1, ...$record], $this->createStub(DataContainer::class));
        $callback($operation);

        return '' !== $operation->getHtml();
    }
}

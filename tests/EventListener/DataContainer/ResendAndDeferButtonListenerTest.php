<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DecisionResendButtonListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PaymentDeferralButtonListener;

/**
 * E8/E10: when "Send decision again" and "Postpone payment term" appear.
 * Hidden means, in Contao: HTML ''.
 */
final class ResendAndDeferButtonListenerTest extends TestCase
{
    public function testEntscheidungErneutSendenNurNachEinerEntscheidungOhneMail(): void
    {
        $listener = new DecisionResendButtonListener();

        self::assertTrue($this->shown($listener, ['decidedAt' => 100, 'decisionSentAt' => null]));
        self::assertFalse($this->shown($listener, ['decidedAt' => 100, 'decisionSentAt' => 200]), 'schon gesendet');
        self::assertFalse($this->shown($listener, ['decidedAt' => 100, 'decisionSentAt' => 0]), 'die 0 der Migration: nichts zu senden');
        self::assertFalse($this->shown($listener, ['decidedAt' => null, 'decisionSentAt' => null]), 'noch nicht entschieden');
    }

    public function testZahlungszielVerschiebenNurBeiOffenemZiel(): void
    {
        $listener = new PaymentDeferralButtonListener();

        self::assertTrue($this->shown($listener, ['paymentDueAt' => 100, 'cancelledAt' => null, 'paymentSettledAt' => null]));
        self::assertFalse($this->shown($listener, ['paymentDueAt' => 0, 'cancelledAt' => null, 'paymentSettledAt' => null]), 'ohne Ziel');
        self::assertFalse($this->shown($listener, ['paymentDueAt' => 100, 'cancelledAt' => 50, 'paymentSettledAt' => null]), 'storniert');
        self::assertFalse($this->shown($listener, ['paymentDueAt' => 100, 'cancelledAt' => null, 'paymentSettledAt' => 50]), 'bezahlt');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function shown(DecisionResendButtonListener|PaymentDeferralButtonListener $listener, array $record): bool
    {
        $operation = new DataContainerOperation('op', ['href' => 'key=op', 'icon' => 'resend.svg', 'label' => ['op', '%s']], ['id' => 1, ...$record], $this->createStub(DataContainer::class));
        $listener($operation);

        return '' !== $operation->getHtml();
    }
}

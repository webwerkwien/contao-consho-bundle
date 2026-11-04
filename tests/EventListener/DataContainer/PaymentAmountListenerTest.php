<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PaymentAmountListener;

/**
 * Geldflüsse E5: money back is not typed into the payment mask.
 */
final class PaymentAmountListenerTest extends TestCase
{
    public function testPositiverBetragGehtDurch(): void
    {
        self::assertSame('25,00', (new PaymentAmountListener())('25,00', $this->dc('0.0000')));
    }

    public function testNegativerOderNullBetragWirdAbgelehnt(): void
    {
        foreach (['-10', '0', '-0,01'] as $typed) {
            try {
                (new PaymentAmountListener())($typed, $this->dc('0.0000'));
                self::fail(\sprintf('"%s" was accepted.', $typed));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEineVonConshoGeschriebeneNegativeZeileBehaeltIhrenBetrag(): void
    {
        self::assertSame('-15.0000', (new PaymentAmountListener())('-15.0000', $this->dc('-15.0000')));
    }

    public function testEineVorhandeneZeileWirdNichtNegativGemacht(): void
    {
        $this->expectException(\RuntimeException::class);

        (new PaymentAmountListener())('-5', $this->dc('-15.0000'));
    }

    private function dc(string $stored): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['amount' => $stored]);

        return $dc;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Checkout\UnknownMethodType;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentIssueSource;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;

class PaymentHandlersTest extends TestCase
{
    public function testEinHandlerWirdUeberSeinenSchluesselGefunden(): void
    {
        $handlers = $this->handlers();

        self::assertInstanceOf(PrepaymentHandler::class, $handlers->get('prepayment'));
        self::assertSame(['prepayment'], $handlers->keys());
        self::assertTrue($handlers->has('prepayment'));
        self::assertFalse($handlers->has('stripe'));
    }

    public function testDenTestAnbieterGibtEsNurMitDerVariableAufEins(): void
    {
        foreach ([null, '', '0', 'true', 'yes'] as $value) {
            $handlers = $this->handlers($value);

            self::assertSame(['prepayment'], $handlers->keys(), var_export($value, true));
            self::assertFalse($handlers->has('test'));
            self::assertFalse($handlers->testPaymentEnabled());
        }

        $handlers = $this->handlers('1');

        self::assertSame(['prepayment', 'test'], $handlers->keys());
        self::assertInstanceOf(TestPaymentHandler::class, $handlers->get('test'));
        self::assertTrue($handlers->testPaymentEnabled());
    }

    public function testEinAusgeschalteterTestAnbieterWirftWieEinUnbekannter(): void
    {
        $this->expectException(UnknownMethodType::class);

        $this->handlers(null)->get('test');
    }

    public function testDerBefundSprichtNurBeiEingeschaltetemTestAnbieter(): void
    {
        self::assertSame([], (new TestPaymentIssueSource($this->handlers(null)))->issues());

        $issues = (new TestPaymentIssueSource($this->handlers('1')))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::TestPaymentActive, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    public function testEinUnbekannterHandlerWirft(): void
    {
        $this->expectException(UnknownMethodType::class);

        $this->handlers()->get('stripe');
    }

    public function testRegisterUndSchnittstelleNennenDenselbenTag(): void
    {
        $tag = (new \ReflectionClass(PaymentHandlerInterface::class))->getAttributes(AutoconfigureTag::class)[0]->getArguments()[0];
        $parameter = (new \ReflectionMethod(PaymentHandlers::class, '__construct'))->getParameters()[0];
        $locator = $parameter->getAttributes(AutowireLocator::class)[0]->getArguments();

        self::assertSame('consho.payment_handler', $tag);
        self::assertSame($tag, $locator[0]);
        self::assertSame('getType', $locator['defaultIndexMethod']);
    }

    private function handlers(string|null $testPayment = null): PaymentHandlers
    {
        return new PaymentHandlers(new ServiceLocator([
            'prepayment' => static fn (): PrepaymentHandler => new PrepaymentHandler(),
            'test' => fn (): TestPaymentHandler => new TestPaymentHandler($this->createMock(UrlGeneratorInterface::class)),
        ]), $testPayment);
    }
}

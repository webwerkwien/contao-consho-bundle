<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\RegistrationActivationListener;

/**
 * Nachlese KA2: "Activate accounts immediately" and "Account activation"
 * exclude each other.
 */
final class RegistrationActivationListenerTest extends TestCase
{
    public function testBeideZusammenAbgelehnt(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener()->save('1', $this->dc(['reg_activate' => '1']));
    }

    public function testNurSofortFreischaltenErlaubt(): void
    {
        self::assertSame('1', $this->listener()->save('1', $this->dc(['reg_activate' => ''])));
    }

    public function testAusgeschaltetNieAbgelehnt(): void
    {
        self::assertSame('', $this->listener()->save('', $this->dc(['reg_activate' => '1'])));
    }

    public function testAutoSubmitNichtAbgelehnt(): void
    {
        $request = new Request([], ['SUBMIT_TYPE' => 'auto']);

        self::assertSame('1', $this->listener($request)->save('1', $this->dc(['reg_activate' => '1'])));
    }

    private function listener(Request|null $request = null): RegistrationActivationListener
    {
        $requests = new RequestStack();
        $requests->push($request ?? new Request());

        return new RegistrationActivationListener($requests);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function dc(array $record): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('getActiveRecord')->willReturn($record);

        return $dc;
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ComplaintDecideButtonListener;

final class ComplaintDecideButtonListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function modules(): iterable
    {
        yield 'list of complaints' => ['consho_complaint', 'consho_complaint'];
        yield 'under the order' => ['consho_order', 'consho_order'];
        yield 'anything else' => ['', 'consho_complaint'];
    }

    /**
     * @dataProvider modules
     */
    public function testLeadsToTheComplaintPageInTheSameModule(string $do, string $expected): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('contao_backend', ['do' => $expected, 'table' => 'tl_consho_complaint', 'key' => 'processComplaint', 'id' => 9])
            ->willReturn('/contao?decide');

        $operation = $this->operation();
        (new ComplaintDecideButtonListener($this->requests(['do' => $do, 'table' => 'tl_consho_complaint_item', 'id' => '9']), $router))($operation);

        self::assertSame('/contao?decide', $operation->getUrl());
        self::assertNull($operation->getHtml(), 'shown');
    }

    public function testHiddenWithoutAComplaint(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::never())->method('generate');

        $operation = $this->operation();
        (new ComplaintDecideButtonListener($this->requests(['do' => 'consho_complaint']), $router))($operation);

        self::assertSame('', $operation->getHtml());
    }

    /**
     * @param array<string, string> $query
     */
    private function requests(array $query): RequestStack
    {
        $requests = new RequestStack();
        $requests->push(new Request($query));

        return $requests;
    }

    private function operation(): DataContainerOperation
    {
        return new DataContainerOperation('decide', ['icon' => 'complaint-process.svg', 'label' => ['Weiter zur Entscheidung', '']], null, $this->createStub(DataContainer::class));
    }
}

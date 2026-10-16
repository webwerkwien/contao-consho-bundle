<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\CoreBundle\Event\RobotsTxtEvent;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use webignition\RobotsTxt\Directive\Directive;
use webignition\RobotsTxt\Directive\UserAgentDirective;
use webignition\RobotsTxt\File\File;
use webignition\RobotsTxt\Record\Record;
use Webwerkwien\ContaoConshoBundle\EventListener\RobotsTxtListener;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

class RobotsTxtListenerTest extends TestCase
{
    public function testAddsTheDisallowRuleOnARootPageWithAShop(): void
    {
        $event = $this->event(shopId: 5);

        $this->listener()($event);

        self::assertStringContainsString('disallow:/*?*cq=', (string) $event->getFile());
    }

    public function testAddsNothingOnARootPageWithoutAShop(): void
    {
        // A multi-tenant installation carries sites that have nothing to do
        // with Consho. A listener firing everywhere would lock other people's
        // pages out of the index — and nobody would notice until rankings went.
        $event = $this->event(shopId: 0);

        $this->listener()($event);

        self::assertStringNotContainsString('cq=', (string) $event->getFile());
    }

    public function testDoesNotBlockPagination(): void
    {
        // Page numbers must stay crawlable, or products that only appear on
        // page three are never found.
        $event = $this->event(shopId: 5);

        $this->listener()($event);

        self::assertStringNotContainsString('page=', (string) $event->getFile());
    }

    public function testAddsTheRuleOnlyOnce(): void
    {
        $event = $this->event(shopId: 5);

        $listener = $this->listener();
        $listener($event);
        $listener($event);

        self::assertSame(1, substr_count((string) $event->getFile(), 'disallow:/*?*cq='));
    }

    public function testWritesIntoTheRecordContaoAlreadyOpened(): void
    {
        // Contao's own listener adds the user-agent * record with its back end
        // rules. Ours must land in THAT record — a second "User-agent: *" block
        // is not what a crawler reads as one rule set.
        $file = new File();
        $record = new Record();
        $record->getUserAgentDirectiveList()->add(new UserAgentDirective('*'));
        $record->getDirectiveList()->add(new Directive('Disallow', '/contao/'));
        $file->addRecord($record);

        $this->listener()($this->event(shopId: 5, file: $file));

        self::assertCount(1, $file->getRecords());
        self::assertStringContainsString('disallow:/contao/', (string) $file);
        self::assertStringContainsString('disallow:/*?*cq=', (string) $file);
    }

    public function testOpensARecordWhenItRunsFirst(): void
    {
        // Listener order is not guaranteed. Running before Contao's own means
        // there is no record yet, and a directive without one is dropped.
        $event = $this->event(shopId: 5);

        $this->listener()($event);

        self::assertStringContainsString('user-agent:*', (string) $event->getFile());
    }

    private function listener(): RobotsTxtListener
    {
        return new RobotsTxtListener(new ShopResolver($this->createMock(ContaoFramework::class)));
    }

    private function event(int $shopId, File|null $file = null): RobotsTxtEvent
    {
        // The field comes out of the row array, not off the model: consho_shop
        // is added to tl_page by our own DCA. See ShopResolver.
        $page = $this->createMock(PageModel::class);
        $page->method('row')->willReturn(['id' => 5, 'consho_shop' => $shopId]);

        return new RobotsTxtEvent($file ?? new File(), new Request(), $page);
    }
}

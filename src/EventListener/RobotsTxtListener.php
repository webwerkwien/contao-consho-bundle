<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Contao\CoreBundle\Event\RobotsTxtEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use webignition\RobotsTxt\Directive\Directive;
use webignition\RobotsTxt\Directive\UserAgentDirective;
use webignition\RobotsTxt\Inspector\Inspector;
use webignition\RobotsTxt\Record\Record;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * Excludes the filter parameter from crawling — by itself.
 *
 * Contao generates robots.txt dynamically and offers this event; Consho writes
 * its own rule rather than putting a line in the documentation for the operator
 * to copy. An instruction that has to be followed is a mistake waiting to
 * happen.
 *
 * ⚠️ Only on root pages that actually have a shop. In a multi-tenant
 * installation a listener firing everywhere would lock other people's sites out
 * of the index — and nobody would notice until the rankings went.
 *
 * Pagination is NOT excluded: page numbers have to stay crawlable, or products
 * appearing only on page three are never discovered.
 */
#[AsEventListener]
final class RobotsTxtListener
{
    private const DISALLOW = '/*?*'.FilterStateCodec::PARAMETER.'=';

    public function __construct(private readonly ShopResolver $shops)
    {
    }

    public function __invoke(RobotsTxtEvent $event): void
    {
        // Asked through ShopResolver rather than read here: consho_shop is
        // added to tl_page by our own DCA and is not declared on PageModel, so
        // it has to come out of the row array — and that knowledge belongs in
        // one place.
        if ($this->shops->shopIdOfPage($event->getRootPage()) < 1) {
            return;
        }

        $file = $event->getFile();

        /*
         * Contao's own listener opens the "user-agent: *" record and puts the
         * back end rules in it. Listener order is not guaranteed though, so if
         * we run first there is no record yet — and a directive without one is
         * dropped. Same idiom as the core listener.
         */
        if (0 === (new Inspector($file, '*'))->getDirectives()->getLength()) {
            $record = new Record();
            $record->getUserAgentDirectiveList()->add(new UserAgentDirective('*'));
            $file->addRecord($record);
        }

        // DirectiveList::add() drops a duplicate by itself, so running twice
        // leaves one line.
        foreach ($file->getRecords() as $record) {
            $record->getDirectiveList()->add(new Directive('Disallow', self::DISALLOW));
        }
    }
}

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Seo\InvalidPathTemplate;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPathTemplate;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;

/**
 * Refuses a path template that cannot address products, while it is saved.
 *
 * Checking here rather than when the route is built is the point: a template
 * that reached the front end and could still fail would not fail for one page —
 * it would fail for every product address in the shop at once.
 *
 * Same posture as GtinListener: refuse at the field rather than report on the
 * start page. An address pattern is not an incomplete shop, it is a decision,
 * and the moment to catch it is while the person who typed it is looking at it.
 */
class PathTemplateListener
{
    public function __construct(private readonly RedirectRecorder $recorder)
    {
    }

    #[AsCallback(table: 'tl_page', target: 'fields.conshoPathTemplate.save')]
    public function __invoke(mixed $value, DataContainer $dc): string
    {
        try {
            $new = ProductPathTemplate::fromString((string) $value);
        } catch (InvalidPathTemplate $e) {
            throw new \RuntimeException($this->message($e));
        }

        $this->rememberOldAddresses($new, $dc);

        return $new->toString();
    }

    /**
     * 🔴 Runs BEFORE the new value is stored, and that is not incidental.
     *
     * Contao collects the submitted fields and writes them in one UPDATE at the
     * end of the request, so the stored pattern here is still the previous one.
     * Record afterwards and the recorder renders the NEW addresses and files
     * redirects that point at themselves.
     */
    private function rememberOldAddresses(ProductPathTemplate $new, DataContainer $dc): void
    {
        $stored = (string) ($dc->getCurrentRecord()['conshoPathTemplate'] ?? '');

        if ('' === $stored || $stored === $new->toString()) {
            return;
        }

        try {
            $previous = ProductPathTemplate::fromString($stored);
        } catch (InvalidPathTemplate) {
            /*
             * The stored pattern is unusable — written past the DCA. The route fell back to {alias}, so the
             * addresses that existed were the fallback's; recording the broken
             * pattern's would file paths nobody ever visited.
             */
            return;
        }

        $this->recorder->record((int) $dc->id, $previous, time());
    }

    /**
     * The three refusals name three different mistakes. "Invalid value" would
     * leave the operator to guess which one — and the {alias} rule is the
     * one that looks arbitrary until it is explained.
     */
    private function message(InvalidPathTemplate $e): string
    {
        $texts = $GLOBALS['TL_LANG']['CONSHO']['errors'] ?? [];

        if (!isset($texts[$e->key])) {
            return $e->getMessage();
        }

        return \sprintf((string) $texts[$e->key], $e->context);
    }
}

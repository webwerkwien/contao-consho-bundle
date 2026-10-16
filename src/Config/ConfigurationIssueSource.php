<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where the back end start page gets its findings from.
 *
 * A seam rather than a convenience: the listener behind it runs on EVERY call
 * of the start page, and a test that had to boot Contao to check a rendering
 * detail would not be written. Behind the interface sit the implementations
 * that talk to the database; in front of it a listener that only formats.
 *
 * Tagged, and therefore plural. A-11 added product safety alongside the
 * configuration findings, and a second implementation of a plain interface
 * would have made the autowiring of the listener ambiguous instead. Whoever
 * adds the next set of findings writes one class and nothing else.
 */
#[AutoconfigureTag('consho.configuration_issue_source')]
interface ConfigurationIssueSource
{
    /**
     * @return list<ConfigurationIssue>
     */
    public function issues(): array;
}

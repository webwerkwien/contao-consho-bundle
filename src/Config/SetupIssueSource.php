<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

/**
 * A source whose findings are read from the start points, and which can read
 * them from EVERY start point that names a shop — published or not.
 *
 * For the setup page only (Nachlese Stufe 2, N1): unpublished ones are checked too. A shop in preparation has nothing but
 * unpublished start points, and its steps "Shopseiten" and "Rechtstexte"
 * said "erledigt" with not a single page in place.
 *
 * issues() stays with the published ones: it feeds the start page and the
 * checkout readiness, and a finding about a start point nobody can reach
 * blocked a working shop once (C-5).
 */
interface SetupIssueSource extends ConfigurationIssueSource
{
    /**
     * @return list<ConfigurationIssue>
     */
    public function issuesForSetup(): array;
}

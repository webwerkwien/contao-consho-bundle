<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\EuMemberStates;

/**
 * Offers only manufacturers that can actually be a responsible person.
 *
 * Two exclusions, and both make an invalid choice unreachable rather than
 * catching it afterwards:
 *
 *  - Seats outside the Union. A responsible person who is not in the EU does
 *    not discharge the duty; that is the whole point of Art. 16.
 *  - The record itself. A manufacturer cannot stand in for itself, and the
 *    entry would look plausible in the list.
 */
class EuResponsibleOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'fields.euResponsible.options')]
    public function __invoke(DataContainer|null $dc = null): array
    {
        /** @var array<int, string> $candidates */
        $candidates = $this->connection->fetchAllKeyValue(
            'SELECT id, title FROM tl_consho_manufacturer WHERE country IN (:countries) AND id != :id ORDER BY title',
            [
                'countries' => EuMemberStates::CODES,
                'id' => null !== $dc ? (int) $dc->id : 0,
            ],
            ['countries' => ArrayParameterType::STRING],
        );

        return $candidates;
    }
}

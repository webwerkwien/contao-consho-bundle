<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\Invoicing;

/**
 * Who issues a shop's invoices (R15): Consho, an installed invoicing module,
 * or nobody — in that order, drawn as one switch (consho-switch).
 *
 * A module that was chosen and then removed stays in the list, marked: Contao
 * drops a stored value that is not among the options when the record is saved
 * next, and the shop would fall back to whatever comes first without anyone
 * deciding it. Its documents wait meanwhile (InvoicingPolicy).
 *
 * The stored value is read from the database, not from the record the caller
 * hands in: a write outside the back end passes the record with the new
 * values already in it, and any name would then count as "stored".
 */
class InvoicingOptionsListener
{
    public function __construct(
        private readonly InvoiceProviders $providers,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.invoicing.options')]
    public function options(DataContainer|null $dc = null): array
    {
        $labels = $GLOBALS['TL_LANG']['tl_consho_shop']['invoicingOptions'] ?? [];
        $options = [Invoicing::CONSHO => (string) ($labels[Invoicing::CONSHO] ?? Invoicing::CONSHO)];

        foreach ($this->providers->names() as $name) {
            $options[$name] = $this->providers->get($name)?->label() ?? $name;
        }

        $id = (int) ($dc?->getCurrentRecord()['id'] ?? 0);
        $stored = $id > 0 ? (string) $this->connection->fetchOne('SELECT invoicing FROM tl_consho_shop WHERE id = ?', [$id]) : '';

        if ('' !== $stored && Invoicing::OFF !== $stored && !isset($options[$stored])) {
            $options[$stored] = \sprintf((string) ($labels['missing'] ?? '%s (not installed)'), $stored);
        }

        $options[Invoicing::OFF] = (string) ($labels[Invoicing::OFF] ?? Invoicing::OFF);

        return $options;
    }
}

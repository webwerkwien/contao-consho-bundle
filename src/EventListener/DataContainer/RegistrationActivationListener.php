<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\DC_Table;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * "Konten sofort freischalten" and "Kontoaktivierung" exclude each other
 * (Nachlese, KA2).
 *
 * Both together would send an activation link for an account that is open
 * already — a mail that asks the customer to do something that has been done.
 * Where a confirmed address matters, Consho checks it itself
 * (`consho_confirmedEmail`), so the switch gives nothing away.
 *
 * Read through getActiveRecord(): `reg_activate` stands before the switch in the
 * palette and has been processed when this runs, so the record carries the
 * submitted value, not the stored one (tl_module.php).
 *
 * Not on an auto-submit: `reg_activate` is a selector and submits the form when
 * clicked. Refusing then would answer a click with an error before the
 * operator could untick the other box — the real save still refuses.
 */
class RegistrationActivationListener
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    #[AsCallback(table: 'tl_module', target: 'fields.consho_activateAtOnce.save')]
    public function save(mixed $value, DataContainer $dc): mixed
    {
        if (!$value || 'auto' === $this->requests->getCurrentRequest()?->request->get('SUBMIT_TYPE')) {
            return $value;
        }

        $record = $dc instanceof DC_Table ? $dc->getActiveRecord() : $dc->getCurrentRecord();

        if (!empty($record['reg_activate'])) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_module']['consho_activateExclusive'] ?? 'Activating accounts at once and the activation mail exclude each other.');
        }

        return $value;
    }
}

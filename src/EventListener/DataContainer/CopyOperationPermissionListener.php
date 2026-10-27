<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The permission check for the copy button of the five child tables.
 *
 * ── Why these five write their copy operation out ─────────────────────────
 *
 * Everywhere else in this bundle `copy` is Contao's own, named rather than
 * written out — an array operation is taken over unchanged
 * (`$operations[$k] = $v` in DefaultOperationsListener) and therefore has
 * neither a permission callback nor an HTTP verb.
 *
 * These five cannot be named, because Contao builds copy in TWO shapes and
 * picks by table (`DefaultOperationsListener::getDefaults()`):
 *
 * | table | href |
 * |---|---|
 * | no ptable, no tree | `act=copy` — duplicate right here |
 * | ptable or tree | `act=paste&mode=copy` — clipboard, then pick a target |
 *
 * All five have a ptable, so naming them would swap one click for two. Measured
 * on tl_consho_tax_rate and tl_consho_order_status:
 *
 * - the clipboard takes the "new record" button out of the list for as long
 *   as it is filled (`$canAddNew = !$blnClipboard` in DC_Table::parentView),
 *   4 create buttons dropped to 0, and it is session state that survives
 *   navigating away — a list that looks broken later, for no visible reason;
 * - tl_consho_tax_rate, tl_consho_price and tl_consho_shipping_price have no
 *   `sorting` column, so `getNewPosition()` skips itself
 *   (`fieldExists('sorting')`) and the paste offered exactly ONE target. The
 *   second click chooses nothing.
 *
 * What the current behaviour does instead is right: `getNewPosition()` sets
 * the pid to the record itself for `mode=copy`, so the duplicate lands
 * directly behind its original, in one click.
 *
 * ── So the two things that hang on the default are fetched by hand ────────
 *
 * - `'method' => 'POST'` in the DCA. It is read from the operation config
 *   whatever its origin (DataContainerOperationsBuilder:276) and it takes the
 *   token out of the URL (:294), so the copy no longer travels by GET.
 * - this callback, which asks the CreateAction voter WHILE THE ROW IS DRAWN.
 *   Without it a voter is only reached in DC_Table::copy(), after the click.
 *
 * The subject is the one Contao builds for the `act=copy` shape — the record
 * as it stands, with no `sorting` override. The override belongs to the paste
 * shape, which is precisely what these tables do not use.
 */
class CopyOperationPermissionListener
{
    /**
     * Kept as a list so the test can hold it against the DCAs: a table that
     * writes out its copy operation and is missing here would have no check
     * at all, and nothing about the button would show it.
     */
    public const TABLES = [
        'tl_consho_order_status',
        'tl_consho_price',
        'tl_consho_property_option',
        'tl_consho_shipping_price',
        'tl_consho_tax_rate',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    #[AsCallback(table: 'tl_consho_order_status', target: 'list.operations.copy.button')]
    #[AsCallback(table: 'tl_consho_price', target: 'list.operations.copy.button')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'list.operations.copy.button')]
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'list.operations.copy.button')]
    #[AsCallback(table: 'tl_consho_tax_rate', target: 'list.operations.copy.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();

        /*
         * No row means there is nothing to judge, and saying no here would
         * grey out a button over a missing record rather than a denied one.
         * DC_Table::copy() still asks the voter after the click.
         */
        if (null === $record) {
            return;
        }

        $table = $operation->getDataContainer()->table;

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.$table, new CreateAction($table, $record))) {
            $operation->disable();
        }
    }
}

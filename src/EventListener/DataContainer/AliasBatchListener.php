<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;

/**
 * The "Generate aliases" button under "Edit multiple".
 *
 * Contao ships the label (MSC.aliasSelected) and the pattern, but not the
 * feature: each table implements the button itself. In the core only
 * tl_article and tl_page have one. Consho needs it on three tables, because a
 * product address may contain the manufacturer and the product type, and
 * records that existed before those fields did carry no alias — the save
 * callback only fires when somebody opens a record.
 *
 * > 🎯 Without this the gap would be closed by an instruction, and an
 * > instruction that has to be followed is a mistake that happens eventually.
 *
 * ── One deliberate difference to the core implementation ──────────────────
 *
 * tl_article does this (contao/dca/tl_article.php:388):
 *
 *     $dc->id = $id;
 *     $dc->activeRecord = $objArticle;
 *
 * `activeRecord` is deprecated since Contao 5, and our alias callbacks read
 * `getActiveRecord()` — which is the stored record merged with submitted
 * values and does NOT look at the property the core sets. A faithful copy
 * would hand every record the same (empty) title.
 *
 * So the row is read through `DataContainer::getCurrentRecord($id)` instead.
 * It reads by id, and it runs the permission voters on the way — the read
 * check comes for free, and the update check is asked separately below, as
 * the core does.
 *
 * ── A regenerated product alias moves the product's address ───────────────
 *
 * The button writes with a direct UPDATE, past the field's save callback — so
 * ProductAliasListener never sees the change. The old address is handed to
 * RedirectRecorder here instead, before the UPDATE, for the same reason as
 * there. Manufacturer and product type aliases are part of product addresses
 * too — the recorder files the addresses of all their products, and ignores
 * the tables whose alias is not in a product address.
 */
class AliasBatchListener
{
    public function __construct(
        private readonly AliasFactory $aliases,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly ContaoFramework $framework,
        private readonly RedirectRecorder $recorder,
    ) {
    }

    /**
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'select.buttons')]
    public function onProduct(array $buttons, DataContainer $dc): array
    {
        return $this->handle($buttons, $dc);
    }

    /**
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'select.buttons')]
    public function onManufacturer(array $buttons, DataContainer $dc): array
    {
        return $this->handle($buttons, $dc);
    }

    /**
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_product_type', target: 'select.buttons')]
    public function onProductType(array $buttons, DataContainer $dc): array
    {
        return $this->handle($buttons, $dc);
    }

    /**
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_property_group', target: 'select.buttons')]
    public function onPropertyGroup(array $buttons, DataContainer $dc): array
    {
        return $this->handle($buttons, $dc);
    }

    /**
     * @param array<string, string> $buttons
     *
     * @return array<string, string>
     */
    private function handle(array $buttons, DataContainer $dc): array
    {
        $table = $dc->table;

        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_EDIT_FIELD_OF_TABLE, $table.'::alias')) {
            return $buttons;
        }

        $request = $this->requests->getCurrentRequest();

        if (null !== $request && null !== $request->request->get('alias') && 'tl_select' === $request->request->get('FORM_SUBMIT')) {
            $this->generateFor($table, $this->selectedIds(), $dc);
        }

        $buttons['alias'] = \sprintf(
            '<button type="submit" name="alias" id="alias" class="tl_submit" accesskey="a">%s</button> ',
            $GLOBALS['TL_LANG']['MSC']['aliasSelected'] ?? 'Generate aliases',
        );

        return $buttons;
    }

    /**
     * @param list<int> $ids
     */
    private function generateFor(string $table, array $ids, DataContainer $dc): void
    {
        foreach ($ids as $id) {
            $record = $dc->getCurrentRecord($id);

            if (null === $record) {
                continue;
            }

            /*
             * Passing an empty typed value is what makes the factory generate
             * from the title — the same path an empty field takes when a
             * record is saved. The uniqueness check comes with it, so two
             * products called the same do not end up sharing an alias.
             */
            $alias = $this->aliases->forRecord($table, '', (string) ($record['title'] ?? ''), $id);

            if ($alias === (string) ($record['alias'] ?? '')) {
                continue;
            }

            if (!$this->security->isGranted(
                ContaoCorePermissions::DC_PREFIX.$table,
                new UpdateAction($table, $record, ['alias' => $alias]),
            )) {
                continue;
            }

            // 🔴 Before the UPDATE: the recorder renders the old address from
            // the database. It compares old and new itself and records nothing
            // for a product that had no alias yet. For a manufacturer or type
            // it records the addresses of all its products; property groups
            // are filter addresses and not its business.
            if ('tl_consho_product' === $table) {
                $this->recorder->recordProductAlias($id, $alias, time());
            } else {
                $this->recorder->recordSegmentAlias($table, $id, $alias, time());
            }

            // A batch change without history would be the one change nobody can
            // undo — and it touches every selected record at once.
            $versions = $this->framework->createInstance(Versions::class, [$table, $id]);
            $versions->initialize();

            $this->connection->update($table, ['alias' => $alias], ['id' => $id]);

            $versions->create();
        }

        /*
         * Post/Redirect/Get. Without it a reload repeats the batch, and the
         * second run would append counters to aliases that were already unique.
         */
        throw new RedirectResponseException($this->requests->getCurrentRequest()?->getRequestUri() ?? '/contao');
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        $session = $this->requests->getSession();

        /** @var array<string, mixed> $current */
        $current = $session->get('CURRENT') ?? [];
        $ids = $current['IDS'] ?? [];

        return array_map(intval(...), \is_array($ids) ? array_values($ids) : []);
    }
}

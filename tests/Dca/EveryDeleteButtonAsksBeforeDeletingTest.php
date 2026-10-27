<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\CoreBundle\Security\DataContainer\DeleteAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CopyOperationPermissionListener;

/**
 * The delete operation of every DCA in this bundle is Contao's own, named
 * rather than written out.
 *
 * ── Why this test exists ─────────────────────────────────────────────────
 *
 * 🔴 Seventeen DCAs carried a hand-written delete operation whose confirm
 * dialog was a Contao 3/4 leftover:
 *
 *     onclick="if(!confirm(document.getElementById('tl_confirm').textContent))return false;…"
 *
 * In Contao 5 `tl_confirm` exists only as a CSS CLASS, never as an id, so
 * `getElementById` returns null and reading `.textContent` throws. An inline
 * handler that throws returns undefined, which does not cancel the click —
 * **the record was deleted on the first click, without being asked.**
 * Measured on a real installation with a throwaway tax class: the operation
 * rendered as a plain `<a href="…act=delete&id=3&rt=…">`, and a GET on that
 * URL alone deleted the row (it landed in tl_undo).
 *
 * Naming the operation instead hands it to DefaultOperationsListener, which
 * brings three things an array cannot have, because an array is copied
 * unchanged (`$operations[$k] = $v`):
 *
 * - the confirm text as a STRING (MSC.deleteConfirm), so the dialog appears;
 * - `method: DELETE`, which renders a POST form with the token in its body
 *   instead of a link that deletes on GET;
 * - `button_callback`, which asks the DeleteAction voter per row while the
 *   list is drawn and greys the button out — without it a voter is only
 *   reached in DC_Table::delete(), i.e. after the click.
 *
 * The labels stay in this bundle's language files either way; they are read
 * from `$GLOBALS['TL_LANG'][$table]['delete']` regardless of where the
 * operation came from.
 *
 * ⚠️ **`edit` and `toggle` are still written out** in 22 and 3 DCAs. They
 * have the same missing permission callback. They are NOT checked here
 * because Contao's `edit` and `toggle` also carry `primary` and
 * `showInHeader`, which would move icons between the row and the ⋯ menu in
 * every list — a change that needs its own decision, not a silent one.
 *
 * `copy` is checked below, but only where Contao's default means the same
 * thing; the reason is in that test.
 */
class EveryDeleteButtonAsksBeforeDeletingTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dcaFiles(): iterable
    {
        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    /**
     * @dataProvider dcaFiles
     */
    public function testDieLoeschenOperationIstContaosEigene(string $file): void
    {
        /*
         * The bare name lands under a NUMERIC key — `'delete',` in a list of
         * `'key' => […]` entries is an unkeyed element. Contao's merge reads
         * the value, not the key (`$operations[$key] = $defaults[$key]`), so
         * what has to be absent is the string key holding an array.
         */
        $dca = self::load($file);

        // Never nothing: a file that suddenly declares no table at all would
        // otherwise pass this test by having nothing to check.
        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach (self::operationsOf($dca) as $table => $operations) {
            self::assertFalse(
                \is_array($operations['delete'] ?? null),
                \sprintf(
                    '%s: the delete operation must be the core default, written as the bare name '
                    ."'delete'. An array is copied unchanged, so it loses the button_callback that "
                    .'greys the button out for a voter, the DELETE method, and the confirm dialog.',
                    $table,
                ),
            );
        }
    }

    /**
     * 🔴 **And the other direction: no button is no prohibition.**
     *
     * The test above on its own let through a DCA that leaves out `delete`
     * entirely — `is_array(null)` is false, so green. That is exactly how
     * `tl_consho_number_range` and `tl_consho_order_item` stood: no delete
     * button in the list and still deletable, because
     * `DC_Table::delete()` and `deleteAll()` ask **only** `notDeletable`,
     * and "Edit multiple" builds its actions from the configuration rather
     * than from the operations list.
     *
     * Measured on a real installation: the multiple edit of the number ranges
     * offered `edit | delete | copy | copyMultiple | cut | override` — the
     * invoice counter was deletable.
     *
     * So: whoever leaves out the button means `notDeletable`. Whoever does not
     * mean it lists the operation.
     *
     * @dataProvider dcaFiles
     */
    public function testWerKeinenLoeschenKnopfHatMeintNotDeletable(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            $operations = $definition['list']['operations'] ?? null;

            // No operations list of its own means Contao builds the defaults,
            // and `delete` is among them. Nothing to check.
            if (!\is_array($operations)) {
                continue;
            }

            $hasDelete = \array_key_exists('delete', $operations)
                || \in_array('delete', $operations, true)
                || \in_array('!delete', $operations, true);

            if ($hasDelete) {
                continue;
            }

            self::assertTrue(
                (bool) ($definition['config']['notDeletable'] ?? false),
                \sprintf(
                    '%s lists operations but no delete, and is not notDeletable. That hides the '
                    .'button without forbidding the act: DC_Table::delete() and deleteAll() ask '
                    .'notDeletable alone, and "select multiple" builds its actions from the config.',
                    $table,
                ),
            );
        }
    }

    /**
     * `copy` — but only where Contao's default means the same thing.
     *
     * Contao builds the copy operation in **two** versions, and which one it
     * becomes depends on the table (`DefaultOperationsListener::getDefaults()`,
     * measured against 5.7.13):
     *
     * | Table | href |
     * |---|---|
     * | without `ptable`, no tree | `act=copy` |
     * | with `ptable` or tree | `act=paste&mode=copy` |
     *
     * Only in the first case is the hand-written
     * `['href' => 'act=copy', 'icon' => 'copy.svg']` **the same act** as the
     * default — it merely lacks `method: POST` and the `button_callback` that
     * runs the CreateAction check while the row is drawn rather than only after
     * the click.
     *
     * In the second case switching would not be a silent gain but different
     * behaviour: "duplicate right away" would become "put it on the clipboard
     * and click a target". The five child tables therefore stay written out —
     * and are checked **instead** for fetching the two things that come with
     * the default themselves: `method: POST` and the permission check from
     * CopyOperationPermissionListener.
     *
     * ⚠️ Without this second part the test would be the silent kind: a child
     * table with ptable would pass it by being skipped.
     *
     * @dataProvider dcaFiles
     */
    public function testKopierenIstContaosEigeneWoDerStandardDasselbeMeint(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            $operations = $definition['list']['operations'] ?? null;

            if (!\is_array($operations)) {
                continue;
            }

            // Exactly the condition from getDefaults(); the listener asks for
            // MODE_TREE, not MODE_TREE_EXTENDED.
            $hasPtable = !empty($definition['config']['ptable'] ?? null);
            $isTreeMode = DataContainer::MODE_TREE === ($definition['list']['sorting']['mode'] ?? null);

            if ($hasPtable || $isTreeMode) {
                $copy = $operations['copy'] ?? null;

                if (!\is_array($copy)) {
                    continue;
                }

                self::assertSame(
                    'POST',
                    $copy['method'] ?? null,
                    \sprintf(
                        '%s writes its copy operation out, so nothing is added to it. Without '
                        ."'method' => 'POST' it renders as a link that duplicates on GET, with the "
                        .'token in the URL.',
                        $table,
                    ),
                );

                self::assertContains(
                    $table,
                    CopyOperationPermissionListener::TABLES,
                    \sprintf(
                        '%s writes its copy operation out but is not in '
                        .'CopyOperationPermissionListener::TABLES, so no voter is asked while the '
                        .'row is drawn. Nothing about the button would show it.',
                        $table,
                    ),
                );

                continue;
            }

            self::assertFalse(
                \is_array($operations['copy'] ?? null),
                \sprintf(
                    '%s has no ptable and is not a tree, so Contaos default copy operation is the '
                    ."very same act=copy. Write it as the bare name 'copy' and it also brings "
                    .'method POST and the button_callback that asks the CreateAction voter while '
                    .'the row is drawn. An array is copied unchanged and has neither.',
                    $table,
                ),
            );
        }
    }

    /**
     * And the other direction to the list in the listener.
     *
     * The test above checks that every written-out copy operation is in
     * `TABLES`. It says nothing about the reverse case: an entry for which
     * there is no written-out operation (any more). It would then hang in the
     * air — an `AsCallback` on an operation that Contao builds itself does not
     * take effect, and nobody would notice.
     */
    public function testJedeTabelleImListenerSchreibtIhreKopierenOperationAuch(): void
    {
        $written = [];

        foreach (self::dcaFiles() as [$file]) {
            foreach (self::load($file) as $table => $definition) {
                if (\is_array($definition['list']['operations']['copy'] ?? null)) {
                    $written[] = $table;
                }
            }
        }

        foreach (CopyOperationPermissionListener::TABLES as $table) {
            self::assertContains(
                $table,
                $written,
                \sprintf(
                    '%s is in CopyOperationPermissionListener::TABLES but does not write out a copy '
                    .'operation. The AsCallback then hangs on nothing: a named operation gets '
                    ."Contao's own callback, and this one is never called.",
                    $table,
                ),
            );
        }
    }

    /**
     * `edit` and `toggle` are Contao's own — and what comes with them is more
     * than a permission check.
     *
     * Both bring `primary`, and **a single `primary` tips the whole list**:
     * `DataContainerOperationsBuilder` then sets `has_primary`, and the template
     * no longer draws any operation **without** the marker in the row but behind
     * the ⋯ menu.
     *
     * Hence the second part below: our own jumps into the child table must
     * carry `primary`, or they disappear from the row without anything failing.
     *
     * @dataProvider dcaFiles
     */
    public function testBearbeitenUndUmschaltenSindContaosEigene(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach (self::operationsOf($dca) as $table => $operations) {
            foreach (['edit', 'toggle'] as $name) {
                self::assertFalse(
                    \is_array($operations[$name] ?? null),
                    \sprintf(
                        "%s: the %s operation must be the bare name '%s'. An array is copied "
                        .'unchanged, so it loses the button_callback that asks the UpdateAction '
                        .'voter while the row is drawn.',
                        $table,
                        $name,
                        $name,
                    ),
                );
            }
        }
    }

    /**
     * 🔴 **The trap when naming `toggle`.**
     *
     * `DefaultOperationsListener::getToggleField()` looks for the field itself —
     * and returns `null` if **none** or **more than one** carries `toggle` (or
     * `reverseToggle`). Without a field there is no default called `toggle`, and
     * a name for which there is no default silently drops out in Contao's merge:
     * `if (!is_array($v)) { continue; }`.
     *
     * ⚠️ **The button would then simply be gone** — no error, no message.
     * A second toggle field in one of these three forms would trigger exactly
     * that, and it would only become visible in the backend.
     *
     * @dataProvider dcaFiles
     */
    public function testWerToggleNenntHatGenauEinUmschaltfeld(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            $operations = $definition['list']['operations'] ?? null;

            if (!\is_array($operations) || !\in_array('toggle', $operations, true)) {
                continue;
            }

            $fields = [];

            foreach ($definition['fields'] ?? [] as $name => $config) {
                if (($config['toggle'] ?? false) || ($config['reverseToggle'] ?? false)) {
                    $fields[] = $name;
                }
            }

            self::assertCount(
                1,
                $fields,
                \sprintf(
                    "%s names the toggle operation, so Contao has to find the field itself. It "
                    .'takes exactly one — none or several make getToggleField() return null, there '
                    .'is then no default called "toggle", and the name drops out of the merge '
                    .'without a word. Found: %s.',
                    $table,
                    [] === $fields ? 'none' : implode(', ', $fields),
                ),
            );
        }
    }

    /**
     * Our own jumps into a child table stay in the row.
     *
     * Since `edit` is named, every list carries `has_primary` — and without the
     * marker an operation slips behind the ⋯ menu. For `tl_consho_order` that
     * would be **five** navigation paths at once; the pencil would be all that
     * was left.
     *
     * In the core, the jump to the children is `primary` as well (`children` in
     * `getDefaults()`), so this is no special case but the same rule for a
     * self-built operation.
     *
     * @dataProvider dcaFiles
     */
    public function testSpruengeInDieKindtabelleBleibenInDerZeile(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach (self::operationsOf($dca) as $table => $operations) {
            foreach ($operations as $name => $operation) {
                if (!\is_array($operation) || !str_starts_with((string) ($operation['href'] ?? ''), 'table=')) {
                    continue;
                }

                self::assertTrue(
                    (bool) ($operation['primary'] ?? false),
                    \sprintf(
                        '%s: the "%s" operation jumps to a child table but is not primary. Since '
                        .'the named edit operation carries primary, everything without it is drawn '
                        .'behind the ⋯ menu instead of in the row.',
                        $table,
                        $name,
                    ),
                );
            }
        }
    }

    /**
     * 🔴 **Every operation stays in the row — `primary` on all of them.**
     *
     * A requirement, and it has a measurable reason:
     * `DataContainerOperation::disable()` removes the `href` and swaps the icon.
     * The template draws such a thing **only in the row**:
     *
     *     {% elseif icon and not menu %}
     *
     * In the ⋯ menu a disabled operation **drops out without replacement**. Seen
     * on a real installation with a restricted user: in the product list, copy
     * and versions were missing entirely instead of standing there greyed out.
     *
     * > 🎯 **A missing button says "does not exist", a grey one says "you may
     * > not".** That is the difference, and it gets lost in the menu.
     *
     * Hence every operation carries `primary`: named ones through the
     * exclamation mark (`'!copy'`), written-out ones through `'primary' => true`.
     * `edit` and `toggle` already bring it from Contao's default.
     *
     * ⚠️ What this does **not** cure: `toggle` is not even created by
     * `handleToggle()` for a field without edit permission — Contao decides that
     * before any placement and does so everywhere in the core.
     *
     * @dataProvider dcaFiles
     */
    public function testJedeOperationBleibtInDerZeile(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach (self::operationsOf($dca) as $table => $operations) {
            foreach ($operations as $key => $operation) {
                // Separators are not an operation.
                if ('-' === $operation) {
                    continue;
                }

                if (\is_array($operation)) {
                    self::assertTrue(
                        (bool) ($operation['primary'] ?? false),
                        \sprintf(
                            '%s: the "%s" operation is written out and not primary, so it is drawn '
                            .'behind the ⋯ menu — and a disabled operation renders nothing there. '
                            .'Add \'primary\' => true.',
                            $table,
                            $key,
                        ),
                    );

                    continue;
                }

                if (str_starts_with((string) $operation, '!')) {
                    continue;
                }

                /*
                 * Only the two defaults that bring `primary` themselves may stand
                 * without an exclamation mark. Anything else would be a button
                 * that vanishes without a trace when the permission is missing.
                 */
                self::assertContains(
                    $operation,
                    ['edit', 'toggle'],
                    \sprintf(
                        '%s: the operation "%s" is named without the "!" prefix, so it does not '
                        .'carry primary and lands in the ⋯ menu, where a disabled operation is not '
                        .'drawn at all. Only edit and toggle bring primary with them.',
                        $table,
                        $operation,
                    ),
                );
            }
        }
    }

    /**
     * 🔴 **Where a voter can grey out the delete button, it must stand in the
     * row.**
     *
     * `DataContainerOperation::disable()` removes the `href` and swaps the icon
     * for `delete--disabled.svg`. The template draws an operation without `href`
     * as a **bare icon — but only in the row**:
     *
     *     {% elseif icon and not menu %}
     *
     * In the ⋯ menu it drops out without replacement. Since `edit` is named,
     * every list carries `has_primary`, and `delete` without `!` only stands in
     * the menu. Measured on a real installation: in the shipping method list
     * there was **no `act=delete` and no `--disabled` icon left in the HTML** —
     * the promise from C-2 ("the delete button is greyed out") had silently
     * disappeared.
     *
     * The protection itself stayed intact the whole time; only its hint became
     * invisible. Exactly the kind of loss that produces no message.
     *
     * @dataProvider dcaFiles
     */
    public function testWoEinVoterDasLoeschenSperrtStehtDerKnopfInDerZeile(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            if (!\in_array($table, self::tablesWithADeleteVoter(), true)) {
                continue;
            }

            $operations = $definition['list']['operations'] ?? [];

            self::assertContains(
                '!delete',
                \is_array($operations) ? $operations : [],
                \sprintf(
                    '%s has a voter that can deny DeleteAction, so its delete operation must be '
                    ."named '!delete'. The '!' sets primary and keeps the button in the row; "
                    .'without it a disabled button is drawn nowhere at all, and the greyed-out '
                    .'state is the whole point.',
                    $table,
                ),
            );
        }
    }

    /**
     * Read from the voters, not copied — otherwise the list would silently be
     * incomplete with the next voter.
     *
     * @return list<string>
     */
    private static function tablesWithADeleteVoter(): array
    {
        $tables = [];

        foreach (glob(__DIR__.'/../../src/Security/*Voter.php') ?: [] as $file) {
            $class = 'Webwerkwien\\ContaoConshoBundle\\Security\\'.basename($file, '.php');

            if (!class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            // A voter that does not answer DeleteAction at all cannot grey out
            // the button either.
            if (!$reflection->hasMethod('supportsType')) {
                continue;
            }

            $voter = $reflection->newInstanceWithoutConstructor();

            /*
             * A voter dedicated to deleting — not one that merely takes it
             * along. ProductPinPlacementVoter answers all four types and
             * explicitly lets reading and deleting through; so it cannot
             * grey out the button at all, and its table (tl_content)
             * belongs to the core anyway.
             */
            if (!$voter->supportsType(DeleteAction::class) || $voter->supportsType(UpdateAction::class)) {
                continue;
            }

            $method = $reflection->getMethod('getTable');
            $method->setAccessible(true);
            $tables[] = $method->invoke($voter);
        }

        return $tables;
    }

    /**
     * 🔴 **Whoever keeps versions also shows the way to them.**
     *
     * `versions` has been a default operation since 5.7 — but Contao only
     * **prepends** the defaults when no declared operation is a name. Our lists
     * have always written everything out, so it dropped out in **twenty** forms
     * without anybody noticing.
     *
     * Shown on a real installation: a shop had 17 versions and the list no
     * `diff` icon. No data loss — the version select sits in the edit form —
     * but the way from the list was missing.
     *
     * ⚠️ The button carries **no** `primary`, so it stands in the ⋯ menu, and
     * `diffCallback()` disables it for records with fewer than two versions.
     * The template does not draw a disabled operation in the menu at all — so
     * the entry only appears where there really is something to compare.
     *
     * @dataProvider dcaFiles
     */
    public function testWerVersioniertZeigtDenVersionenKnopf(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            $operations = $definition['list']['operations'] ?? null;

            // No list of its own means Contao builds the defaults, `versions`
            // is among them. And without editing the default does not exist at
            // all ($canEdit in getDefaults()) — a name without a default would
            // silently drop out, as with `toggle`.
            if (
                !\is_array($operations)
                || !($definition['config']['enableVersioning'] ?? false)
                || ($definition['config']['notEditable'] ?? false)
            ) {
                continue;
            }

            self::assertContains(
                'versions',
                // The exclamation mark sets `primary`; what is checked here is THAT
                // the operation is there, not where it stands.
                array_map(static fn ($v) => \is_string($v) ? ltrim($v, '!') : $v, $operations),
                \sprintf(
                    '%s has enableVersioning but does not list the versions operation. Contao only '
                    .'PREPENDS its defaults while no declared operation is name-only, and this DCA '
                    .'names several — so the button would simply not exist, and the records would '
                    .'be versioned with no way into the history from the list.',
                    $table,
                ),
            );
        }
    }

    /**
     * The same defect from the other side: whichever operation carries it,
     * `tl_confirm` is a Contao 3/4 id that no Contao 5 page renders.
     *
     * A named delete operation cannot bring it back, but a hand-written
     * `copy`, `toggle` or an operation of this bundle's own could — copied
     * from an old example, exactly as these seventeen were.
     *
     * @dataProvider dcaFiles
     */
    public function testKeineDcaGreiftNachDerAltenBestaetigung(string $file): void
    {
        self::assertStringNotContainsString(
            'tl_confirm',
            (string) file_get_contents($file),
            basename($file).": tl_confirm is a CSS class in Contao 5, never an id. getElementById "
            .'returns null, reading .textContent throws, and the click is not cancelled — the record '
            .'is deleted without a dialog.',
        );
    }

    /**
     * @param array<string, mixed> $dca
     *
     * @return array<string, array<string, mixed>>
     */
    private static function operationsOf(array $dca): array
    {
        $found = [];

        foreach ($dca as $table => $definition) {
            $operations = $definition['list']['operations'] ?? null;

            if (\is_array($operations)) {
                $found[$table] = $operations;
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $file): array
    {
        unset($GLOBALS['TL_DCA']);

        // Two of these files extend core tables and reach for a palette Contao
        // declares; PaletteManipulator throws without it. See
        // EveryDcaSurvivesContaoMigrationsTest for why they are stubbed rather
        // than skipped.
        $GLOBALS['TL_DCA']['tl_member']['palettes']['default'] = '{personal_legend},firstname,lastname;';
        $GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = '{title_legend},title,alias;';
        $GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'] = '{title_legend},title,alias;';
        $GLOBALS['TL_DCA']['tl_module']['palettes']['registration'] = '{title_legend},name,type;{email_legend},reg_activate;';

        require $file;

        return $GLOBALS['TL_DCA'] ?? [];
    }
}

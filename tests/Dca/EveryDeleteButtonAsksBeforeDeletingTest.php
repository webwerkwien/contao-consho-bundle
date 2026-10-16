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
 * Measured on c5 on 2026-09-23 with a throwaway tax class: the operation
 * rendered as a plain `<a href="…act=delete&id=3&rt=…">`, and a GET on that
 * URL alone deleted the row (tl_undo 299).
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
 * every list — a change that needs its own acceptance, not a silent one. See
 * the findings in docs/block-c-warenkorb-checkout.md.
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
     * 🔴 **Und die Gegenrichtung: kein Knopf ist kein Verbot.**
     *
     * Der Test oben allein ließ eine DCA durchgehen, die `delete` ganz
     * weglässt — `is_array(null)` ist falsch, also grün. Genau so standen
     * `tl_consho_number_range` und `tl_consho_order_item` da: kein
     * Löschen-Knopf in der Liste und trotzdem löschbar, denn
     * `DC_Table::delete()` und `deleteAll()` fragen **nur** `notDeletable`,
     * und „Mehrere bearbeiten" baut seine Aktionen aus der Konfiguration
     * statt aus der Operationsliste.
     *
     * Am 2026-09-23 auf c5 gemessen: Die Mehrfachbearbeitung der
     * Nummernkreise bot `edit | delete | copy | copyMultiple | cut |
     * override` an — der Rechnungszähler war löschbar.
     *
     * Also: Wer den Knopf weglässt, meint `notDeletable`. Wer es nicht
     * meint, listet die Operation.
     *
     * @dataProvider dcaFiles
     */
    public function testWerKeinenLoeschenKnopfHatMeintNotDeletable(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            $operations = $definition['list']['operations'] ?? null;

            // Keine eigene Operationsliste heißt: Contao baut die Standards,
            // und `delete` ist dabei. Nichts zu prüfen.
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
     * `copy` — aber nur dort, wo Contaos Standard dasselbe meint.
     *
     * Contao baut die Kopieren-Operation in **zwei** Fassungen, und welche es
     * wird, hängt an der Tabelle (`DefaultOperationsListener::getDefaults()`,
     * gemessen an 5.7.13):
     *
     * | Tabelle | href |
     * |---|---|
     * | ohne `ptable`, kein Baum | `act=copy` |
     * | mit `ptable` oder Baum | `act=paste&mode=copy` |
     *
     * Nur im ersten Fall ist das von Hand geschriebene
     * `['href' => 'act=copy', 'icon' => 'copy.svg']` **dieselbe Tat** wie der
     * Standard — es fehlen ihm bloß `method: POST` und der
     * `button_callback`, der die CreateAction-Prüfung beim Zeichnen der Zeile
     * stellt statt erst nach dem Klick.
     *
     * Im zweiten Fall wäre das Umstellen kein stiller Gewinn, sondern ein
     * anderes Verhalten: aus „sofort duplizieren" würde „in die
     * Zwischenablage legen und ein Ziel anklicken". Die fünf Kindtabellen
     * bleiben deshalb ausgeschrieben — und werden **stattdessen** darauf
     * geprüft, dass sie sich die zwei Dinge selbst holen, die am Standard
     * hängen: `method: POST` und die Rechteprüfung aus
     * CopyOperationPermissionListener.
     *
     * ⚠️ Ohne diesen zweiten Teil wäre der Test die stille Sorte: Eine
     * Kindtabelle mit ptable würde ihn bestehen, indem er sie überspringt.
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

            // Genau die Bedingung aus getDefaults(); der Listener fragt
            // MODE_TREE, nicht MODE_TREE_EXTENDED.
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
     * Und die Gegenrichtung zur Liste im Listener.
     *
     * Der Test oben prüft, dass jede ausgeschriebene Kopieren-Operation in
     * `TABLES` steht. Er sagt nichts über den umgekehrten Fall: einen
     * Eintrag, zu dem es keine ausgeschriebene Operation (mehr) gibt. Der
     * hinge dann leer in der Luft — ein `AsCallback` auf eine Operation, die
     * Contao selbst baut, greift nicht, und niemand würde es bemerken.
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
     * `edit` und `toggle` sind Contaos eigene — und was daran hängt, ist
     * mehr als eine Rechteprüfung.
     *
     * Beide bringen `primary` mit, und **ein einziges `primary` kippt die
     * ganze Liste**: `DataContainerOperationsBuilder` setzt daraufhin
     * `has_primary`, und das Template zeichnet jede Operation **ohne** das
     * Kennzeichen nicht mehr in die Zeile, sondern hinter das ⋯-Menü.
     *
     * Deshalb steht unten der zweite Teil: Unsere eigenen Sprünge in die
     * Kindtabelle müssen `primary` tragen, sonst verschwinden sie aus der
     * Zeile, ohne dass irgendetwas scheitert.
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
     * 🔴 **Die Falle beim Benennen von `toggle`.**
     *
     * `DefaultOperationsListener::getToggleField()` sucht das Feld selbst —
     * und gibt `null` zurück, wenn **keines** oder **mehr als eines**
     * `toggle` (oder `reverseToggle`) trägt. Ohne Feld gibt es keinen
     * Standard namens `toggle`, und ein Name, zu dem es keinen Standard
     * gibt, fällt in Contaos Merge stillschweigend heraus:
     * `if (!is_array($v)) { continue; }`.
     *
     * ⚠️ **Der Knopf wäre dann einfach weg** — kein Fehler, keine Meldung.
     * Ein zweites Umschaltfeld in einer dieser drei Masken würde genau das
     * auslösen, und zwar erst im Backend sichtbar.
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
     * Unsere eigenen Sprünge in eine Kindtabelle bleiben in der Zeile.
     *
     * Seit `edit` benannt ist, trägt jede Liste `has_primary` — und ohne das
     * Kennzeichen rutscht eine Operation hinter das ⋯-Menü. Bei
     * `tl_consho_order` wären das **fünf** Navigationswege auf einmal; übrig
     * bliebe der Stift.
     *
     * Im Kern ist der Sprung zu den Kindern ebenfalls `primary`
     * (`children` in `getDefaults()`), das hier ist also keine Sonderlocke,
     * sondern dieselbe Regel für eine selbstgebaute Operation.
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
     * 🔴 **Jede Operation bleibt in der Zeile — `primary` an allen.**
     *
     * Michaels Entscheidung am 2026-09-23, und sie hat einen messbaren
     * Grund: `DataContainerOperation::disable()` nimmt den `href` weg und
     * tauscht das Symbol. Das Template zeichnet so etwas **nur in der
     * Zeile**:
     *
     *     {% elseif icon and not menu %}
     *
     * Im ⋯-Menü fällt eine gesperrte Operation **ersatzlos weg**. Auf c5 mit
     * einem eingeschränkten Benutzer gesehen: In der Produktliste fehlten
     * Kopieren und Versionen vollständig, statt grau dazustehen.
     *
     * > 🎯 **Ein fehlender Knopf sagt „gibt es nicht", ein grauer sagt
     * > „darfst du nicht".** Das ist der Unterschied, und er geht im Menü
     * > verloren.
     *
     * Deshalb trägt jede Operation `primary`: benannte über das
     * Ausrufezeichen (`'!copy'`), ausgeschriebene über `'primary' => true`.
     * `edit` und `toggle` bringen es aus Contaos Standard schon mit.
     *
     * ⚠️ Was das **nicht** heilt: `toggle` wird für ein Feld ohne
     * Bearbeitungsrecht von `handleToggle()` gar nicht erst erzeugt — das
     * entscheidet Contao vor jeder Stellung und tut es im Kern überall.
     *
     * @dataProvider dcaFiles
     */
    public function testJedeOperationBleibtInDerZeile(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach (self::operationsOf($dca) as $table => $operations) {
            foreach ($operations as $key => $operation) {
                // Trenner sind keine Operation.
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
                 * Nur die beiden Standards, die `primary` selbst mitbringen,
                 * dürfen ohne Ausrufezeichen dastehen. Alles andere wäre ein
                 * Knopf, der bei fehlendem Recht spurlos verschwindet.
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
     * 🔴 **Wo ein Voter den Löschen-Knopf ausgrauen kann, muss er in der
     * Zeile stehen.**
     *
     * `DataContainerOperation::disable()` nimmt den `href` weg und tauscht
     * das Symbol gegen `delete--disabled.svg`. Das Template zeichnet eine
     * Operation ohne `href` als **blankes Symbol — aber nur in der Zeile**:
     *
     *     {% elseif icon and not menu %}
     *
     * Im ⋯-Menü fällt sie ersatzlos weg. Seit `edit` benannt ist, trägt
     * jede Liste `has_primary`, und `delete` steht ohne `!` nur noch im
     * Menü. Am 2026-09-23 auf c5 gemessen: In der Versandarten-Liste war
     * **kein `act=delete` und kein `--disabled`-Symbol mehr im HTML** — die
     * Zusicherung aus C-2 („der Löschen-Knopf ist ausgegraut") war still
     * verschwunden.
     *
     * Der Schutz selbst blieb die ganze Zeit intakt; unsichtbar wurde nur
     * sein Hinweis. Genau die Sorte Verlust, die keine Meldung erzeugt.
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
     * Aus den Votern gelesen, nicht abgeschrieben — sonst wäre die Liste
     * beim nächsten Voter still unvollständig.
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

            // Ein Voter, der DeleteAction gar nicht beantwortet, kann den
            // Knopf auch nicht ausgrauen.
            if (!$reflection->hasMethod('supportsType')) {
                continue;
            }

            $voter = $reflection->newInstanceWithoutConstructor();

            /*
             * Ein Voter, der dem Löschen gewidmet ist — nicht einer, der es
             * bloß mit entgegennimmt. ProductPinPlacementVoter beantwortet
             * alle vier Typen und lässt Lesen und Löschen ausdrücklich
             * durch; er kann den Knopf also gar nicht ausgrauen, und seine
             * Tabelle (tl_content) gehört ohnehin dem Kern.
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
     * 🔴 **Wer versioniert, zeigt den Weg zu den Versionen auch.**
     *
     * `versions` ist seit 5.7 eine Standardoperation — aber Contao stellt
     * die Standards nur **voran**, wenn keine deklarierte Operation ein Name
     * ist. Unsere Listen schreiben seit jeher alles aus, also fiel sie in
     * **zwanzig** Masken weg, ohne dass es auffiel.
     *
     * Am 2026-09-23 belegt: Shop 1 hatte 17 Versionen und die Liste kein
     * `diff`-Symbol. Kein Datenverlust — das Versions-Auswahlfeld steht im
     * Bearbeiten-Formular —, aber der Weg aus der Liste fehlte.
     *
     * ⚠️ Der Knopf trägt **kein** `primary`, steht also im ⋯-Menü, und
     * `diffCallback()` schaltet ihn für Datensätze mit weniger als zwei
     * Versionen ab. Eine abgeschaltete Operation zeichnet das Template im
     * Menü gar nicht — der Eintrag erscheint also nur dort, wo es wirklich
     * etwas zu vergleichen gibt.
     *
     * @dataProvider dcaFiles
     */
    public function testWerVersioniertZeigtDenVersionenKnopf(string $file): void
    {
        $dca = self::load($file);

        self::assertNotEmpty($dca, $file.' declares no DCA at all.');

        foreach ($dca as $table => $definition) {
            $operations = $definition['list']['operations'] ?? null;

            // Keine eigene Liste heißt: Contao baut die Standards, `versions`
            // ist dabei. Und ohne Bearbeiten gibt es den Standard gar nicht
            // ($canEdit in getDefaults()) — ein Name ohne Standard fiele
            // still heraus, wie bei `toggle`.
            if (
                !\is_array($operations)
                || !($definition['config']['enableVersioning'] ?? false)
                || ($definition['config']['notEditable'] ?? false)
            ) {
                continue;
            }

            self::assertContains(
                'versions',
                // Das Ausrufezeichen setzt `primary`; geprüft wird hier, DASS
                // die Operation dasteht, nicht wo sie steht.
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

/*
 * Consho — tabs over the translation sections of a back end mask.
 *
 * A classic script, not a Stimulus controller. Contao bundles Stimulus into
 * its own backend.js and exposes neither the application nor a way for
 * another bundle to register a controller (core-bundle 5.7.13,
 * assets/backend.js). Its contao--tabs component does not fit either: it
 * adds close buttons that remove a panel from the DOM, and in a form that
 * would take the fields — and what was typed into them — along.
 *
 * The sections come first, the tabs second. Without this script the
 * translation sections are ordinary collapsible fieldsets and every field
 * stays reachable. The script only shows and hides them; it never changes
 * what the form submits, and it never touches the collapse state Contao
 * stores for the user.
 *
 * Opt-in by id: only the fieldsets TranslationFieldsListener adds are called
 * pal_consho_translation_…, so every other mask is left alone.
 */
(() => {
    const PANEL = 'fieldset[id^="pal_consho_translation_"]';
    const STORAGE_KEY = 'consho-translation-tab';

    // Storage can throw (private mode, blocked site data). A lost preference
    // must never cost the mask its tabs.
    const storageKey = (form) => STORAGE_KEY + ':' + (form?.querySelector('input[name="FORM_SUBMIT"]')?.value ?? '');

    const recall = (form) => {
        try {
            return sessionStorage.getItem(storageKey(form));
        } catch (e) {
            return null;
        }
    };

    const remember = (form, id) => {
        try {
            sessionStorage.setItem(storageKey(form), id);
        } catch (e) {
            // see above
        }
    };

    const init = () => {
        const first = document.querySelector(PANEL);

        if (!first || first.parentNode.dataset.conshoTabs) {
            return;
        }

        const container = first.parentNode;
        const panels = [...container.children].filter((el) => el.matches(PANEL));

        // A single translation language needs no tabs — the section is enough.
        if (panels.length < 2) {
            return;
        }

        container.dataset.conshoTabs = '1';

        const form = first.closest('form');
        const list = document.createElement('div');

        list.className = 'consho-translation-tabs';
        list.setAttribute('role', 'tablist');

        const tabs = panels.map((panel) => {
            const tab = document.createElement('button');

            tab.type = 'button';
            tab.id = panel.id + '_tab';
            tab.textContent = (panel.querySelector('legend')?.textContent ?? panel.id).trim();
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);

            panel.classList.add('consho-translation-panel');
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);

            list.append(tab);

            return tab;
        });

        container.insertBefore(list, panels[0]);

        const activate = (index, focus = false) => {
            panels.forEach((panel, i) => {
                const active = i === index;

                panel.hidden = !active;

                // A tab that opens onto a folded section would need a second
                // click. Unfolding here is local to this page view: Contao only
                // stores the state when its own toggle button is used.
                if (active) {
                    panel.classList.remove('collapsed');
                }

                tabs[i].setAttribute('aria-selected', String(active));
                tabs[i].tabIndex = active ? 0 : -1;
            });

            if (focus) {
                tabs[index].focus();
            }

            remember(form, panels[index].id);
        };

        // After a failed save the section with the error comes first — the
        // editor has to see why nothing was stored.
        const withError = panels.findIndex((panel) => panel.querySelector('.error, .tl_error'));
        const stored = panels.findIndex((panel) => panel.id === recall(form));

        activate(withError >= 0 ? withError : Math.max(stored, 0));

        list.addEventListener('click', (event) => {
            const index = tabs.indexOf(event.target.closest('[role="tab"]'));

            if (index >= 0) {
                activate(index);
            }
        });

        list.addEventListener('keydown', (event) => {
            const current = tabs.indexOf(document.activeElement);
            const last = tabs.length - 1;

            if (current < 0) {
                return;
            }

            const next = {
                ArrowRight: current === last ? 0 : current + 1,
                ArrowLeft: current === 0 ? last : current - 1,
                Home: 0,
                End: last,
            }[event.key];

            if (undefined === next) {
                return;
            }

            event.preventDefault();
            activate(next, true);
        });

        // Two ways to reach a field in a hidden section without clicking its
        // tab: the browser's own validation, and Contao's jump navigation
        // (jump-targets-controller dispatches "scrollto" on the fieldset).
        // Both switch to the section instead of pointing at something unseen.
        const reveal = (event) => {
            const index = panels.indexOf(event.target?.closest?.(PANEL));

            if (index >= 0 && panels[index].hidden) {
                activate(index);
            }
        };

        form?.addEventListener('invalid', reveal, true);
        container.addEventListener('contao--jump-targets:scrollto', reveal);
    };

    // The script sits in the <head>, so the mask is not there yet on the first
    // load; Turbo replaces the body on later visits without running it again.
    // init() is idempotent, so answering every one of these is safe.
    ['DOMContentLoaded', 'turbo:load', 'turbo:render', 'turbo:frame-render'].forEach((type) => {
        document.addEventListener(type, init);
    });
})();

/*
 * Consho — the dashboard's warnings and notes open as a dialog (DB2).
 * In this file because Consho loads it on every back end
 * page already (ContaoConshoBundle, custom_js).
 *
 * One listener on the document, not one per button: Turbo replaces the body
 * without running the script again, and a delegated listener keeps working.
 * Closing needs no script — the dialog's <form method="dialog"> and Esc.
 */
(() => {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest?.('[data-consho-dialog]');
        const dialog = opener ? document.getElementById(opener.dataset.conshoDialog) : null;

        if (dialog && 'function' === typeof dialog.showModal) {
            event.preventDefault();
            dialog.showModal();
        }
    });
})();

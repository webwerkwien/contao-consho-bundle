<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Contao\BackendUser;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\StringUtil;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Config\AcknowledgementStore;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Puts Consho's findings into the "Hinweise" box of the back end start page.
 *
 * ── Why the hook and not be_welcome ──────────────────────────────────────
 *
 * Overriding be_welcome.html.twig would replace the whole page, and only ONE
 * extension can win that. It is also a core template that changed several times
 * between 4.13 and 5.7, so the copy would quietly fall behind. For a single
 * customer project overriding it is legitimate; for a distributed bundle it is
 * not. The hook adds to the box and coexists with everything else.
 *
 * ⚠️ **This runs on EVERY call of the start page.** The source behind the
 * interface may therefore only count, never diagnose. A back end that an
 * extension makes sluggish does not get repaired, it gets switched off.
 */
#[AsHook('getSystemMessages')]
class BackendSystemMessagesListener implements ResetInterface
{
    /**
     * Per request — reset() empties it, so a long-running worker does not
     * show yesterday's findings.
     *
     * @var list<ConfigurationIssue>|null
     */
    private array|null $issues = null;

    /**
     * @param iterable<ConfigurationIssueSource> $sources
     */
    public function __construct(
        #[AutowireIterator('consho.configuration_issue_source')]
        private readonly iterable $sources,
        private readonly AcknowledgementStore $acknowledgements,
        private readonly ContaoCsrfTokenManager|null $tokens = null,
        private readonly Security|null $security = null,
        private readonly RequestStack|null $requests = null,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly UrlGeneratorInterface|null $router = null,
    ) {
    }

    /**
     * The start page (DB2): the warnings, and the notes as one line pointing
     * at the dashboard — for a user who may open it. Whoever may not keeps the
     * notes here, or they would see them nowhere.
     */
    public function __invoke(): string
    {
        if (!$this->mayOpenDashboard()) {
            return $this->panel();
        }

        $issues = $this->issues();
        $this->takeNoteOfSubmitted($issues);

        $acknowledged = array_flip($this->acknowledgements->fingerprints());
        $warnings = [];
        $notes = 0;

        foreach (array_filter($issues, $this->maySee(...)) as $issue) {
            if ($issue->finding->isWarning()) {
                $warnings[] = '<p>'.$this->sentence($issue).'</p>';
            } elseif (!isset($acknowledged[$issue->fingerprint()])) {
                ++$notes;
            }
        }

        $pointer = 0 === $notes ? [] : [\sprintf(
            '<p>%s <a href="%s">%s</a></p>',
            StringUtil::specialchars(\sprintf($GLOBALS['TL_LANG']['CONSHO']['dashboard']['notesElsewhere'] ?? '%d Hinweise im Shop-Dashboard.', $notes)),
            StringUtil::specialchars($this->router?->generate('contao_backend', ['do' => 'consho_dashboard']) ?? ''),
            StringUtil::specialchars($GLOBALS['TL_LANG']['CONSHO']['dashboard']['open'] ?? 'Öffnen'),
        )];

        return $this->box('tl_error', $warnings).$this->box('tl_info', $pointer);
    }

    /**
     * Every finding of every source, once per request — the start page renders
     * its messages more than once, and the dashboard reads them for its status
     * line too.
     *
     * @return list<ConfigurationIssue>
     */
    public function issues(): array
    {
        if (null !== $this->issues) {
            return $this->issues;
        }

        $issues = [];

        foreach ($this->sources as $source) {
            /*
             * One failing source must not take the back end with it (review
             * Abo-7, S2): between unpacking a release and contao:migrate a
             * source reads a column or table that is not there yet, and an
             * exception here answered every back end page with a 500 — the
             * pages an operator needs to see what is wrong. Logged, skipped.
             */
            try {
                $issues = [...$issues, ...$source->issues()];
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The configuration check %s failed: %s', $source::class, $e->getMessage()), ['exception' => $e]);
            }
        }

        return $this->issues = $issues;
    }

    /**
     * Warnings and notes, each note with its "Zur Kenntnis genommen" — on the
     * dashboard (DB2), and on the start page for whoever cannot open it.
     */
    public function panel(): string
    {
        $lines = $this->lines();

        // No findings, no box — not an empty one. An empty panel on the start
        // page reads as "something is broken here", every day.
        return $this->box('tl_error', $lines['warnings']).$this->box('tl_info', $lines['notes']);
    }

    /**
     * The same findings as panel(), as two boxes apart — the dashboard counts
     * them and shows each in its own dialog (Michael, 2026-10-09, DB1–DB3).
     * Counted is what this user may see, and of the notes only those nobody
     * has taken note of yet: exactly what the list shows.
     *
     * @return array{warnings: int, notes: int, warningsHtml: string, notesHtml: string}
     */
    public function summary(): array
    {
        $lines = $this->lines();

        return [
            'warnings' => \count($lines['warnings']),
            'notes' => \count($lines['notes']),
            'warningsHtml' => $this->box('tl_error', $lines['warnings']),
            'notesHtml' => $this->box('tl_info', $lines['notes']),
        ];
    }

    /**
     * @return array{warnings: list<string>, notes: list<string>}
     */
    private function lines(): array
    {
        $issues = $this->issues();
        $this->takeNoteOfSubmitted($issues);

        $acknowledged = array_flip($this->acknowledgements->fingerprints());

        $warnings = [];
        $notes = [];

        foreach (array_filter($issues, $this->maySee(...)) as $issue) {
            $line = '<p>'.$this->sentence($issue).'</p>';

            /*
             * ⚠️ Warnings are never acknowledgeable. A missing tax rate makes
             * orders from that country fail at checkout; a button that made it
             * disappear would be a button that breaks the shop quietly. Like
             * notes, they only go to who may put them right (H2) — a user who
             * sees none of them still reads "Einrichtung unvollständig" at the
             * shop on the dashboard.
             */
            if ($issue->finding->isWarning()) {
                $warnings[] = $line;

                continue;
            }

            if (isset($acknowledged[$issue->fingerprint()])) {
                continue;
            }

            $notes[] = $line.$this->acknowledgeButton($issue);
        }

        return ['warnings' => $warnings, 'notes' => $notes];
    }

    public function reset(): void
    {
        $this->issues = null;
    }

    /**
     * Whether the current user may open a module in which the finding is put
     * right (Michael, 2026-10-07, H1, H2). Administrators see everything,
     * including what no module repairs. Without a security layer — the console,
     * a test — nothing is held back.
     */
    public function maySee(ConfigurationIssue $issue): bool
    {
        if (null === $this->security || $this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        foreach ($issue->finding->fixedIn() as $module) {
            if ($this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, $module)) {
                return true;
            }
        }

        return false;
    }

    private function mayOpenDashboard(): bool
    {
        return null !== $this->security && $this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'consho_dashboard');
    }

    /**
     * Records a click and reloads the page as a GET.
     *
     * A POST, not a link: Contao validates the request token on POST requests
     * only (RequestTokenListener), and a GET that changes state would be one
     * crafted image tag away from being clicked by somebody else.
     *
     * And a redirect afterwards, not just a filtered list. Two reasons, both
     * seen rather than assumed: the start page renders its messages more than
     * once per request, so the note stayed visible in the very response that
     * stored it — and without the redirect a browser refresh would post the
     * form again.
     *
     * @param list<ConfigurationIssue> $issues
     */
    private function takeNoteOfSubmitted(array $issues): void
    {
        // From the request, not Input::post(): a fingerprint is hex and needs
        // none of Contao's input encoding, and this way the path is testable
        // (review M6/M19/M20 — no test had ever touched it).
        $request = $this->requests?->getCurrentRequest();

        if (null === $request || !$request->isMethod('POST')) {
            return;
        }

        $submitted = $request->request->getString('consho_ack');

        if ('' === $submitted) {
            return;
        }

        $user = $this->security?->getUser();
        $userId = $user instanceof BackendUser ? (int) $user->id : 0;

        foreach ($issues as $issue) {
            // Matched against the CURRENT findings rather than trusted from the
            // request: a fingerprint that no longer describes anything must not
            // be storable, or the table fills with claims about nothing. And
            // only a finding this user is shown: the acknowledgement counts
            // for everybody, so a hidden note must not be dismissable by hand.
            if ($issue->fingerprint() === $submitted && !$issue->finding->isWarning() && $this->maySee($issue)) {
                $this->acknowledgements->acknowledge($issue, $userId);

                throw new RedirectResponseException($request->getRequestUri());
            }
        }
    }

    private function acknowledgeButton(ConfigurationIssue $issue): string
    {
        return \sprintf(
            '<form method="post" class="consho-ack">'
            .'<input type="hidden" name="FORM_SUBMIT" value="consho_ack">'
            .'<input type="hidden" name="REQUEST_TOKEN" value="%s">'
            .'<input type="hidden" name="consho_ack" value="%s">'
            .'<button type="submit" class="consho-ack__button">%s</button>'
            .'</form>',
            StringUtil::specialchars($this->tokens?->getDefaultTokenValue() ?? ''),
            StringUtil::specialchars($issue->fingerprint()),
            StringUtil::specialchars($GLOBALS['TL_LANG']['CONSHO']['acknowledge'] ?? 'acknowledge'),
        );
    }

    /**
     * @param list<string> $lines
     */
    private function box(string $class, array $lines): string
    {
        if ([] === $lines) {
            return '';
        }

        return \sprintf('<div class="%s">%s</div>', $class, implode('', $lines));
    }

    /**
     * The finding as the operator reads it, escaped HTML. Public for the
     * setup page (block I), which shows the same sentences under its steps.
     */
    public function sentence(ConfigurationIssue $issue): string
    {
        $code = $issue->finding->value;

        /*
         * Falls back to the bare code rather than to an empty string: a missing
         * translation has to LOOK wrong. An unlabelled bullet on the start page
         * tells nobody anything and gets ignored instead of reported.
         */
        $template = $GLOBALS['TL_LANG']['CONSHO']['findings'][$code] ?? $code;

        $replacements = [];

        foreach ($issue->context as $key => $value) {
            /*
             * Decoded, then escaped, and both are needed.
             *
             * 🔴 Found on c5 on 2026-09-22 while measuring the C-6 findings: a
             * shop called "Testshop 2 (DE)" appeared as "Testshop 2 &#40;DE&#41;".
             * Contao stores the brackets encoded (Input::encodeInsertTags keeps
             * them out of insert tags), so specialchars() alone turns the
             * ampersand into &amp; and the operator reads the entity. They then
             * go looking for a shop of that name.
             *
             * Escaping still happens LAST: the template is ours, the values are
             * not, and a title containing "<" must not take the start page
             * apart. Decoding first does not weaken that — it only makes the
             * text say what the operator typed.
             */
            // Der zweite Parameter entfernt Insert-Tags: decodeEntities()
            // holt auch die {{ }} zurück, die Input::encodeInsertTags beim
            // Speichern gerade unschädlich gemacht hat, und specialchars()
            // fasst geschweifte Klammern nicht an. Heute nicht ausnutzbar —
            // die Systemmeldungen werden ohne Insert-Tag-Parser ausgegeben —,
            // aber das ist eine Eigenschaft der Aufrufstelle, nicht dieser
            // (Review 2026-09-22).
            $replacements['%'.$key.'%'] = StringUtil::specialchars(StringUtil::decodeEntities($value), true);
        }

        return strtr((string) $template, $replacements);
    }
}

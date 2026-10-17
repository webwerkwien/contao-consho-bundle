<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDecision;

/**
 * The back-end mask of a cancellation (Abo-7, P57).
 *
 * onload: everything is read-only, except — while an extraordinary
 * cancellation waits — the decision: state, end of contract, reason for a
 * rejection. Read-only: such a widget is not submitted, so nothing in the
 * row changes (Widget; `disabled` would do the same). No version menu: restoring
 * an old version would bring back an undecided row and a second decision
 * (see LockedRecordListener).
 *
 * state.save: accepted needs an end of contract, rejected needs a reason.
 * effectiveAt.save: not before the cancellation arrived — from receipt on
 * ("fristlos") is a decision the operator may take, earlier is a typo.
 *
 * onsubmit: CancellationDecision applies it once and mails the customer —
 * after Contao's transaction where "Mehrere bearbeiten" opened one (see
 * PaymentRecordListener).
 *
 * CancellationDecision as a closure, built only when a decision is applied —
 * the terminate listener exists on every request and console command (see
 * PaymentRecordListener).
 */
class CancellationListener
{
    private const DECISION = ['state', 'effectiveAt', 'rejectReason'];

    /** @var array<int, true> */
    private array $deferred = [];

    /**
     * @param \Closure(): CancellationDecision $decision
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        #[AutowireServiceClosure(CancellationDecision::class)]
        private readonly \Closure $decision,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[AsCallback(table: 'tl_consho_cancellation', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        $GLOBALS['TL_DCA']['tl_consho_cancellation']['config']['hideVersionMenu'] = true;

        $id = null === $dc ? 0 : (int) $dc->id;
        $row = $id < 1 ? false : $this->connection->fetchAssociative('SELECT kind, decidedAt FROM tl_consho_cancellation WHERE id = ?', [$id]);
        $pending = false !== $row && CancellationDecision::isPending($row);

        foreach ($GLOBALS['TL_DCA']['tl_consho_cancellation']['fields'] as $name => $field) {
            if (isset($field['inputType']) && (!$pending || !\in_array($name, self::DECISION, true))) {
                $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields'][$name]['eval']['readonly'] = true;
            }
        }

        if ($pending) {
            $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields']['state']['options'] = ['received', 'accepted', 'rejected'];

            // The operator decides a day, not a minute (acceptance Abo-7:
            // "31.10.2026" was refused for lacking hh:mm) — 00:00 of that day,
            // like the customer's requested date. Shown as date and time
            // again once decided.
            $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields']['effectiveAt']['eval']['rgxp'] = 'date';
        }
    }

    #[AsCallback(table: 'tl_consho_cancellation', target: 'fields.state.save')]
    public function checkDecision(mixed $value, DataContainer $dc): mixed
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_cancellation'] ?? [];

        if ('accepted' === $value && '' === $this->posted('effectiveAt', (int) $dc->id)) {
            throw new \RuntimeException((string) ($lang['acceptNeedsDate'] ?? 'An accepted cancellation needs an end of contract.'));
        }

        if ('rejected' === $value && '' === $this->posted('rejectReason', (int) $dc->id)) {
            throw new \RuntimeException((string) ($lang['rejectNeedsReason'] ?? 'A rejection needs a reason.'));
        }

        if (!\in_array($value, ['received', 'accepted', 'rejected'], true)) {
            throw new \RuntimeException((string) ($lang['notADecision'] ?? 'Not a decision.'));
        }

        return $value;
    }

    /**
     * The date arrives converted by its rgxp (datim → timestamp).
     */
    #[AsCallback(table: 'tl_consho_cancellation', target: 'fields.effectiveAt.save')]
    public function checkEffectiveAt(mixed $value, DataContainer $dc): mixed
    {
        if (null === $value || '' === $value) {
            return $value;
        }

        $receivedAt = (int) $this->connection->fetchOne('SELECT receivedAt FROM tl_consho_cancellation WHERE id = ?', [(int) $dc->id]);

        if ((int) $value < $receivedAt) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_cancellation']['endBeforeReceipt'] ?? 'The end of contract cannot be before the cancellation arrived.'));
        }

        return $value;
    }

    #[AsCallback(table: 'tl_consho_cancellation', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $id = (int) $dc->id;

        if ($id < 1) {
            return;
        }

        if ($this->connection->isTransactionActive()) {
            $this->deferred[$id] = true;

            return;
        }

        ($this->decision)()->apply($id, time());
    }

    /**
     * A posted field of this record, trimmed. "Mehrere bearbeiten" names it
     * `<field>_<id>` (review Abo-7, L2).
     */
    private function posted(string $field, int $id): string
    {
        $posted = $this->requests->getCurrentRequest()?->request;

        if (null === $posted) {
            return '';
        }

        return trim((string) ($posted->get($field.'_'.$id) ?? $posted->get($field) ?? ''));
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    #[AsEventListener(ConsoleEvents::TERMINATE)]
    public function onTerminate(): void
    {
        $ids = array_keys($this->deferred);
        $this->deferred = [];

        foreach ($ids as $id) {
            try {
                ($this->decision)()->apply($id, time());
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The decision on cancellation %d could not be applied: %s', $id, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}

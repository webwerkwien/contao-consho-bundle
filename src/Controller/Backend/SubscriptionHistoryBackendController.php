<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;

/**
 * "Verlauf" at a subscription: its cancellations, pauses and price offers
 * (Abo-10, P130) on one page, newest first. All are a record of what happened
 * to the contract; child lists side by side made the operator look in
 * several places.
 *
 * The page only reads. A cancellation still opens in its own mask, where an
 * extraordinary one is decided (CancellationListener), and a decision whose
 * mail did not leave can be sent again from here, as from the cancellation
 * list (DecisionResendButtonListener). The two child tables stay in the
 * module, so their own lists and masks keep working.
 */
#[Autoconfigure(public: true)]
final class SubscriptionHistoryBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_subscription_history.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly UrlGeneratorInterface $router,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        // The id is a subscription's only in its own table (H-10).
        $id = 'tl_consho_subscription' === $dc->table ? (int) $dc->id : 0;
        $subscription = $id > 0 ? $this->connection->fetchAssociative('SELECT id, subscriptionNumber FROM tl_consho_subscription WHERE id = ?', [$id]) : false;

        if (false === $subscription) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'consho_subscription')) {
            throw new AccessDeniedHttpException();
        }

        // The request is the subscription's; the child tables' labels are not loaded yet.
        $system = $this->framework->getAdapter(System::class);
        $system->loadLanguageFile('tl_consho_cancellation');
        $system->loadLanguageFile('tl_consho_subscription_pause');
        $system->loadLanguageFile('tl_consho_subscription_price');

        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $lang = $GLOBALS['TL_LANG']['tl_consho_subscription'] ?? [];
        $cancellationLang = $GLOBALS['TL_LANG']['tl_consho_cancellation'] ?? [];
        $pauseLang = $GLOBALS['TL_LANG']['tl_consho_subscription_pause'] ?? [];
        $priceLang = $GLOBALS['TL_LANG']['tl_consho_subscription_price'] ?? [];
        $entries = [];

        $cancellations = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_cancellation WHERE pid = ? ORDER BY receivedAt DESC, id DESC', [$id]);

        foreach ($cancellations as $row) {
            // Who cancelled (Abo-9): the operator above all — as with a pause.
            $details = array_values(array_filter([
                (string) ($cancellationLang['kinds'][$row['kind']] ?? $row['kind']),
                (string) ($cancellationLang['sources'][$row['source']] ?? $row['source']),
            ], static fn (string $part): bool => '' !== $part));

            if (null !== $row['effectiveAt'] && (int) $row['effectiveAt'] > 0) {
                $details[] = \sprintf((string) ($lang['historyEffective'] ?? '%s'), CancellationForm::day((int) $row['effectiveAt'], $language));
            }

            $state = [(string) ($cancellationLang['states'][$row['state']] ?? $row['state'])];
            $superseding = self::supersededBy($row, $cancellations);

            if (null !== $superseding) {
                $state[] = \sprintf((string) ($lang['historySuperseded'] ?? '%s'), (string) $superseding['number']);
            }

            $entries[] = [
                'at' => (int) $row['receivedAt'],
                'id' => (int) $row['id'],
                'when' => $this->moment((int) $row['receivedAt'], $language),
                'type' => (string) ($lang['historyCancellation'] ?? 'Cancellation'),
                'title' => (string) $row['number'],
                'details' => implode(' · ', $details),
                'state' => implode(' · ', $state),
                'editUrl' => $this->url(['table' => 'tl_consho_cancellation', 'act' => 'edit', 'id' => $row['id']]),
                'showUrl' => $this->url(['table' => 'tl_consho_cancellation', 'act' => 'show', 'id' => $row['id']]),
                // As DecisionResendButtonListener: decided, but the mail did not leave.
                'resendUrl' => null !== $row['decidedAt'] && null === $row['decisionSentAt']
                    ? $this->url(['table' => 'tl_consho_cancellation', 'key' => 'resendDecision', 'id' => $row['id']])
                    : null,
            ];
        }

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_subscription_pause WHERE pid = ? ORDER BY requestedAt DESC, id DESC', [$id]) as $row) {
            $withdrawn = (int) $row['withdrawnAt'] > 0;

            $entries[] = [
                'at' => (int) $row['requestedAt'],
                'id' => (int) $row['id'],
                'when' => $this->moment((int) $row['requestedAt'], $language),
                'type' => (string) ($lang['historyPause'] ?? 'Pause'),
                'title' => CancellationForm::day((int) $row['pauseFrom'], $language).' – '.CancellationForm::day((int) $row['pauseUntil'], $language),
                'details' => \sprintf((string) ($lang['historyMonths'] ?? '%d'), (int) $row['months']).' · '.($pauseLang['sources'][$row['source']] ?? $row['source']),
                'state' => $withdrawn
                    ? \sprintf((string) ($lang['historyWithdrawn'] ?? '%s'), CancellationForm::day((int) $row['withdrawnAt'], $language))
                    : '',
                'editUrl' => null,
                'showUrl' => $this->url(['table' => 'tl_consho_subscription_pause', 'act' => 'show', 'id' => $row['id']]),
                'resendUrl' => null,
            ];
        }

        // Abo-10 (P130): the price offers, from the day each was made.
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_subscription_price WHERE pid = ? ORDER BY createdAt DESC, id DESC', [$id]) as $row) {
            $details = [
                \sprintf((string) ($lang['historyPriceFrom'] ?? '%s'), CancellationForm::day((int) $row['effectiveAt'], $language)),
                (bool) $row['decrease']
                    ? (string) ($priceLang['decrease'][0] ?? 'decrease')
                    : \sprintf((string) ($lang['historyPriceDeadline'] ?? '%s'), CancellationForm::day((int) $row['deadlineAt'], $language)),
            ];
            $state = [(string) ($priceLang['states'][$row['state']] ?? $row['state'])];

            if ('' !== (string) $row['answeredBy']) {
                $state[] = (string) ($priceLang['answers'][$row['answeredBy']] ?? $row['answeredBy']);
            }

            if ((int) $row['appliedAt'] > 0) {
                $state[] = \sprintf((string) ($lang['historyPriceApplied'] ?? '%s'), CancellationForm::day((int) $row['appliedAt'], $language));
            }

            $entries[] = [
                'at' => (int) $row['createdAt'],
                'id' => (int) $row['id'],
                'when' => $this->moment((int) $row['createdAt'], $language),
                'type' => (string) ($lang['historyPrice'] ?? 'Price offer'),
                'title' => $this->money((string) $row['oldUnitNet'], (string) $row['currency'], $language).' → '.$this->money((string) $row['newUnitNet'], (string) $row['currency'], $language),
                'details' => implode(' · ', $details),
                'state' => implode(' · ', $state),
                'editUrl' => null,
                'showUrl' => $this->url(['table' => 'tl_consho_subscription_price', 'act' => 'show', 'id' => $row['id']]),
                'resendUrl' => null,
            ];
        }

        // Newest first across all; the id keeps two entries of one second in order.
        usort($entries, static fn (array $a, array $b): int => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'number' => (string) $subscription['subscriptionNumber'],
            'entries' => $entries,
            'back' => $system->getReferer(),
            'lang' => $lang,
        ]);
    }

    /**
     * The cancellation that moved this one's end forward (Abo-9). Of all
     * in force — effective, or accepted — the earliest end is the contract's:
     * the recorder takes an ordinary one only if it ends earlier (P134), and
     * an accepted extraordinary one ends it at its own date. So one in force
     * with an earlier end supersedes this one, whenever it was received or
     * decided. The record keeps its own date; the history says that it no
     * longer holds. A rejected or waiting one moves nothing.
     *
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $all
     *
     * @return array<string, mixed>|null the earliest-ending of those
     */
    private static function supersededBy(array $row, array $all): array|null
    {
        $inForce = static fn (array $c): bool => \in_array((string) $c['state'], ['effective', 'accepted'], true) && (int) ($c['effectiveAt'] ?? 0) > 0;

        if (!$inForce($row)) {
            return null;
        }

        $by = null;

        foreach ($all as $other) {
            if ($inForce($other) && (int) $other['effectiveAt'] < (int) $row['effectiveAt']
                && (null === $by || (int) $other['effectiveAt'] < (int) $by['effectiveAt'])) {
                $by = $other;
            }
        }

        return $by;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function url(array $params): string
    {
        return $this->router->generate('contao_backend', ['do' => 'consho_subscription'] + $params);
    }

    /**
     * A net per unit as the back end reads it, with the currency.
     */
    private function money(string $amount, string $currency, string $language): string
    {
        $locale = '' !== $language ? str_replace('-', '_', $language) : 'de';

        return (string) (new \NumberFormatter($locale, \NumberFormatter::CURRENCY))->formatCurrency((float) $amount, '' !== $currency ? $currency : 'EUR');
    }

    private function moment(int $timestamp, string $language): string
    {
        $locale = '' !== $language ? str_replace('-', '_', $language) : 'de';

        return (string) (new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, date_default_timezone_get()))->format($timestamp);
    }
}

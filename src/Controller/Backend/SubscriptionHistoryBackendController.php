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
 * "Verlauf" at a subscription: its cancellations and pauses on one page,
 * newest first. Both are a record of what happened to the contract; two
 * child lists side by side made the operator look in two places.
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

        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $lang = $GLOBALS['TL_LANG']['tl_consho_subscription'] ?? [];
        $cancellationLang = $GLOBALS['TL_LANG']['tl_consho_cancellation'] ?? [];
        $pauseLang = $GLOBALS['TL_LANG']['tl_consho_subscription_pause'] ?? [];
        $entries = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_cancellation WHERE pid = ? ORDER BY receivedAt DESC, id DESC', [$id]) as $row) {
            $details = [(string) ($cancellationLang['kinds'][$row['kind']] ?? $row['kind'])];

            if (null !== $row['effectiveAt'] && (int) $row['effectiveAt'] > 0) {
                $details[] = \sprintf((string) ($lang['historyEffective'] ?? '%s'), CancellationForm::day((int) $row['effectiveAt'], $language));
            }

            $entries[] = [
                'at' => (int) $row['receivedAt'],
                'id' => (int) $row['id'],
                'when' => $this->moment((int) $row['receivedAt'], $language),
                'type' => (string) ($lang['historyCancellation'] ?? 'Cancellation'),
                'title' => (string) $row['number'],
                'details' => implode(' · ', $details),
                'state' => (string) ($cancellationLang['states'][$row['state']] ?? $row['state']),
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

        // Newest first across both; the id keeps two entries of one second in order.
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
     * @param array<string, mixed> $params
     */
    private function url(array $params): string
    {
        return $this->router->generate('contao_backend', ['do' => 'consho_subscription'] + $params);
    }

    private function moment(int $timestamp, string $language): string
    {
        $locale = '' !== $language ? str_replace('-', '_', $language) : 'de';

        return (string) (new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, date_default_timezone_get()))->format($timestamp);
    }
}

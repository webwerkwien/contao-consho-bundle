<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;

/**
 * "Entscheidung erneut senden" (E10) only where there is one to resend: a
 * decided cancellation whose mail did not leave. Everywhere else the button
 * would offer a mail about nothing — or a second one about a decision the
 * customer already has.
 */
class DecisionResendButtonListener
{
    #[AsCallback(table: 'tl_consho_cancellation', target: 'list.operations.resendDecision.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();

        if (null === ($record['decidedAt'] ?? null) || null !== ($record['decisionSentAt'] ?? null)) {
            $operation->hide();
        }
    }
}

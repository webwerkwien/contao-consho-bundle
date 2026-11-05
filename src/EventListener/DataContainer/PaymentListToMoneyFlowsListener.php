<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The payments of an order are listed on "Geldflüsse"; the plain payment
 * list leads there.
 *
 * Contao 5.7 takes the way back from a record out of the table hierarchy
 * (DcaUrlAnalyzer): "Zurück", "Speichern und schließen" and the redirect
 * after deleting all go to the parent's list of payments. Sending that list
 * on brings each of them back to the page they started from.
 *
 * Only the list view of one order's payments, read on GET: with `act` or
 * `key` set, the record or the action is the subject.
 *
 * `revise` is what the list itself would have done first: deleting the
 * draft of a new payment left without saving (DC_Table::reviseTable(), a row
 * with tstamp 0). Redirected before that, the draft would stay and show on
 * "Geldflüsse" as a payment of 0.
 */
final class PaymentListToMoneyFlowsListener
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'config.onload')]
    public function __invoke(DataContainer|null $dc = null): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request || !$request->isMethodSafe()) {
            return;
        }

        $query = $request->query;
        $order = $query->getInt('id');

        if ($query->has('act') || $query->has('key') || $query->has('popup') || $order < 1) {
            return;
        }

        [$table, $draft] = explode('.', $query->getString('revise'), 2) + ['', ''];

        if ('tl_consho_payment' === $table && (int) $draft > 0) {
            $this->connection->executeStatement('DELETE FROM tl_consho_payment WHERE id = ? AND pid = ? AND tstamp = 0', [(int) $draft, $order]);
        }

        throw new RedirectResponseException($this->router->generate('contao_backend', ['do' => 'consho_order', 'key' => 'moneyFlows', 'id' => $order]));
    }
}

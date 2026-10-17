<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Mail\DeliverabilityChecker;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Mail\SenderAddress;

/**
 * "Testmail senden" at a mail configuration (C-9, Michael 2026-09-25:
 * „Testmail-Funktion, wo man einen Empfänger eintragen kann").
 *
 * The first real confirmation failed on the SMTP login, and it took a
 * purchase to find out. A test mail says the same in one click — with the
 * server's own answer ("535 authentication failed") in the message, because
 * that answer is what an operator needs to act on.
 *
 * GET shows a small form with the recipient, prefilled with the back end
 * user's address; POST sends and redirects back to the form (POST, redirect,
 * GET), so a reload does not send again. The POST carries Contao's request
 * token and is checked by its RequestTokenListener like every back end form.
 *
 * ⚠️ Contao prints messages as HTML, unescaped (Message.php). The recipient
 * is typed in, and the error is the SERVER's answer — for Graph a response
 * body from Microsoft or a proxy. Both are escaped before they go in
 * (review 2026-09-25).
 *
 * The page is RETURNED as a string, unlike the document actions: this is a
 * form that belongs inside the back end frame, which is exactly what
 * Backend::getBackendModule() does with a returned string.
 *
 * Sent through MailTransportFactory — the same transport, time limit and
 * sender the confirmations use, not an approximation of them.
 */
#[Autoconfigure(public: true)]
final class MailTransportTestController
{
    public const TEMPLATE = '@Contao/backend/consho_mail_test.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly MailTransportFactoryInterface $transports,
        private readonly Environment $twig,
        private readonly DeliverabilityChecker $deliverability,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $id = (int) $dc->id;
        $row = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_mail_transport WHERE id = ?', [$id]) : false;

        if (false === $row) {
            throw new NotFoundHttpException();
        }

        // An update rather than a read (review 2026-09-25): sending uses the
        // stored credentials and the shop's sender address.
        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_mail_transport', new UpdateAction('tl_consho_mail_transport', $row))) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_mail_transport'] ?? [];

        if (null !== $request && $request->isMethod('POST')) {
            $recipient = trim((string) $request->request->get('recipient'));
            $message = $this->framework->getAdapter(Message::class);

            if (false === filter_var($recipient, \FILTER_VALIDATE_EMAIL)) {
                $message->addError(\sprintf($lang['testInvalid'] ?? '%s', StringUtil::specialchars($recipient)));
            } else {
                $error = $this->send($row, $recipient);

                null === $error
                    ? $message->addConfirmation(\sprintf($lang['testSent'] ?? '%s', StringUtil::specialchars($recipient)))
                    : $message->addError(\sprintf($lang['testFailed'] ?? '%s', StringUtil::specialchars($error)));
            }

            throw new RedirectResponseException($request->getUri());
        }

        $user = $this->security->getUser();

        return $this->twig->render(self::TEMPLATE, [
            /*
             * The page is not a Contao list or mask, and those are what print
             * the stored messages (Message::generate() in DC_Table). Without
             * this the confirmation waited unseen for the next list (Michael,
             * 2026-09-25: „keine optische Bestätigung im BE").
             */
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            // Decoded: Twig escapes, and "&#40;" would show as such.
            'title' => SenderAddress::decode((string) $row['title']),
            'sender' => (string) $row['senderAddress'],
            'recipient' => $user instanceof BackendUser ? (string) $user->email : '',
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
            'deliverability' => $this->deliverability($row),
        ]);
    }

    /**
     * SPF, DMARC and alignment next to the technical test (D-7, M5).
     *
     * Block A (4.x) promised this as a finding "in the mask"; the checker was
     * built on 2026-09-09 with its tests and never called — measured in D-7.
     * Here, not on the start page: it asks DNS, and the start page is built
     * on every back end request. A technically successful send alone is the
     * green tick that hid a dead Brevo key (spec A).
     *
     * Who authenticates: the SMTP user name (often an account name, not an
     * address — then nothing to compare, see the checker), for Graph the
     * mailbox it sends as, for sendmail nobody.
     *
     * @param array<string, mixed> $row
     *
     * @return array{clean: bool, problems: list<string>, dkim: string}
     */
    private function deliverability(array $row): array
    {
        $sender = (string) $row['senderAddress'];
        $authenticated = match ((string) $row['type']) {
            'smtp' => (string) $row['username'],
            'graph' => $sender,
            default => '',
        };

        $report = $this->deliverability->check($sender, $authenticated);
        $texts = $GLOBALS['TL_LANG']['CONSHO']['deliverability'] ?? [];
        $replace = ['%domain%' => (string) $report->senderDomain, '%authenticated%' => (string) $report->authenticatedDomain];

        return [
            'clean' => $report->isClean(),
            'problems' => array_map(
                static fn ($problem): string => strtr((string) ($texts[$problem->value] ?? $problem->value), $replace),
                $report->problems,
            ),
            'dkim' => null === $report->senderDomain ? '' : strtr((string) ($texts['dkimNotVerifiable'] ?? ''), $replace),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return string|null the reason it failed, null when it was handed over
     */
    private function send(array $row, string $recipient): string|null
    {
        // Contao stores the title with its input encoding; a mail is plain text.
        $title = SenderAddress::decode((string) $row['title']);

        try {
            $this->transports->create($row)->send(
                (new Email())
                    ->from(SenderAddress::of($row))
                    ->to($recipient)
                    ->subject(\sprintf('Consho: Testmail — %s', $title))
                    ->text(\sprintf(
                        "Diese Testmail kam über die Mail-Konfiguration „%s\" (%s), gesendet am %s.\n\nThis test mail came through the mail configuration \"%s\".\n",
                        $title,
                        (string) $row['type'],
                        date('d.m.Y H:i:s'),
                        $title,
                    )),
            );
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}

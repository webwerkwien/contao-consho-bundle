<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDelivery;

/**
 * "PDF" and "HTML" at a document in the back end (C-8) — the operator's way
 * to an invoice until the order page (C-10) gives the customer theirs.
 *
 * Registered as `key` actions of the consho_order module (config.php), so the
 * back end has already checked the module and the table before this runs.
 *
 * ⚠️ The ReadAction below asks the DataContainer voters about THIS record,
 * because a key action is not a DataContainer action and would otherwise not
 * ask at all. What it can answer today is only the module access again:
 * Consho has no voter for tl_consho_document, so Contao's TableAccessVoter
 * decides. It is the place where a restriction per
 * shop or per user takes effect the day such a voter exists — not a check that
 * already distinguishes documents.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 *
 * 🔴 The response is THROWN, not returned. Backend::getBackendModule() sends
 * only a RedirectResponse on its own; any other Response it unwraps with
 * getContent() and appends to the back end page (Backend.php, the `key`
 * branch). Returned, the invoice appeared inside the back end frame — and the
 * PDF would have been its bytes pasted into HTML.
 *
 * ⚠️ What went wrong is said to the operator, never printed on the invoice:
 * an archived file that no longer matches, or a logo that is gone, becomes a
 * back end message on the next page — the PDF itself carries only the
 * "regenerated" note the customer is entitled to see (8.3).
 */
#[Autoconfigure(public: true)]
final class DocumentBackendController
{
    public function __construct(
        private readonly DocumentDelivery $delivery,
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
    ) {
    }

    public function pdf(DataContainer $dc): never
    {
        $this->pdfOf($this->readableRow((int) $dc->id));
    }

    /**
     * "Lieferschein" at a delivery (dev#2): the note of THIS parcel, found by
     * the delivery — the button is shown only where there is one
     * (DeliveryListener).
     */
    public function deliveryNotePdf(DataContainer $dc): never
    {
        $id = $this->connection->fetchOne("SELECT id FROM tl_consho_document WHERE delivery = ? AND type = 'deliveryNote'", [(int) $dc->id]);

        $this->pdfOf($this->readableRow(false === $id ? 0 : (int) $id));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pdfOf(array $row): never
    {
        $delivered = $this->delivery->pdf((int) $row['id']) ?? throw new NotFoundHttpException();

        $this->warn($delivered['archiveProblem'], $delivered['logoMissing'], (string) $row['documentNumber']);

        throw new ResponseException(new Response($delivered['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $delivered['fileName']),
        ] + self::privateHeaders()));
    }

    public function html(DataContainer $dc): never
    {
        $row = $this->readableRow((int) $dc->id);
        $rendered = $this->delivery->html((int) $row['id']) ?? throw new NotFoundHttpException();

        // The document NUMBER in the message, not the id or the file name —
        // it is what the operator finds in the list.
        $this->warn(false, $rendered['logoMissing'], (string) $row['documentNumber']);

        throw new ResponseException(new Response($rendered['html'], 200, ['Content-Type' => 'text/html; charset=utf-8'] + self::privateHeaders()));
    }

    /**
     * @return array<string, mixed>
     */
    private function readableRow(int $id): array
    {
        $row = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_document WHERE id = ?', [$id]) : false;

        if (false === $row) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_document', new ReadAction('tl_consho_document', $row))) {
            throw new AccessDeniedHttpException();
        }

        return $row;
    }

    private function warn(bool $archiveProblem, bool $logoMissing, string $name): void
    {
        $message = $this->framework->getAdapter(Message::class);

        if ($archiveProblem) {
            $message->addError(\sprintf($GLOBALS['TL_LANG']['tl_consho_document']['archiveProblem'] ?? '%s', $name));
        }

        if ($logoMissing) {
            $message->addError(\sprintf($GLOBALS['TL_LANG']['tl_consho_document']['logoMissing'] ?? '%s', $name));
        }
    }

    /**
     * An invoice is personal data: no cache along the way, no index, and no
     * referrer telling a linked site which document was open.
     *
     * @return array<string, string>
     */
    private static function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}

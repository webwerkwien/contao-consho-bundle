<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\DocumentBackendController;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentStore;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDelivery;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceRenderer;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DocumentBackendControllerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_document');
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_document', ['id' => 4, 'pid' => 1, 'documentNumber' => 'RE-00007', 'payload' => '{}']);
    }

    public function testDasPdfKommtPrivatUndMitDateinamen(): void
    {
        $response = $this->responseOf(fn () => $this->controller(granted: true)->pdf($this->dc(4)));

        self::assertSame('%PDF-x', $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('RE-00007.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    /**
     * A `key` action is not a DataContainer action: without the question in
     * the controller, the DC voters would not be asked about this record at all.
     * ⚠️ Today only the module access decides there (no voter of its own for
     * documents) — the test ensures THAT the question is asked and a no is obeyed.
     */
    public function testOhneLeserechtGibtEsDenBelegNicht(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(granted: false)->pdf($this->dc(4));
    }

    public function testEinUnbekannterBelegIst404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller(granted: true)->html($this->dc(99));
    }

    /**
     * The warning names the DOCUMENT NUMBER — not a file name such as
     * "RE-00013.pdf", and for HTML not merely the ID.
     */
    public function testDieWarnungNenntDieBelegnummer(): void
    {
        $this->connection->update('tl_consho_document', ['archiveState' => 'failed'], ['id' => 4]);
        $GLOBALS['TL_LANG']['tl_consho_document']['archiveProblem'] = 'Beleg %s: Ablage';

        $message = $this->createMock(Adapter::class);
        $message->expects(self::once())->method('__call')->with('addError', ['Beleg RE-00007: Ablage']);

        try {
            $this->responseOf(fn () => $this->controller(granted: true, message: $message)->pdf($this->dc(4)));
        } finally {
            unset($GLOBALS['TL_LANG']);
        }
    }

    public function testDieBarrierefreieFassungKommtAlsHtml(): void
    {
        $response = $this->responseOf(fn () => $this->controller(granted: true)->html($this->dc(4)));

        self::assertSame('<html>x</html>', $response->getContent());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    /**
     * 🔴 The response must be THROWN. Returned, Contao wraps its content in the
     * back-end page (Backend::getBackendModule, branch `key`) — the invoice then
     * appears inside the back-end frame.
     */
    private function responseOf(\Closure $action): Response
    {
        try {
            $action();
        } catch (ResponseException $e) {
            return $e->getResponse();
        }

        self::fail('Die Antwort wurde nicht als ResponseException geworfen.');
    }

    /**
     * @param Adapter<Message>|null $message
     */
    private function controller(bool $granted, Adapter|null $message = null): DocumentBackendController
    {
        $renderer = $this->createMock(InvoiceRenderer::class);
        $renderer->method('pdf')->willReturn(['pdf' => '%PDF-x', 'logoMissing' => false]);
        $renderer->method('html')->willReturn(['html' => '<html>x</html>', 'logoMissing' => false]);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($granted);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($message ?? $this->createMock(Adapter::class));

        return new DocumentBackendController(
            new DocumentDelivery(new DbalDocumentStore($this->connection), $renderer, $this->createMock(VirtualFilesystemInterface::class)),
            $this->connection,
            $security,
            $framework,
        );
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

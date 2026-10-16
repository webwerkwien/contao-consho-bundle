<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\Input;
use Contao\InputEncodingMode;
use PHPUnit\Framework\TestCase;

class MailTransportDcaTest extends TestCase
{
    // The user name as well (review 2026-09-25): o'brien@… is a legal address.
    private const SECRETS = ['username', 'password', 'clientSecret'];

    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_mail_transport.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    /**
     * 🔴 Gefunden bei der Abnahme von C-9 (2026-09-25): Der erste echte
     * Versand scheiterte mit „535 authentication failed".
     *
     * Ein Textfeld liest über Input::post() im Modus encodeAll und schreibt
     * `# < > ( ) \ = " '` in HTML-Entities um — BEVOR TransportSecretListener
     * verschlüsselt. Ein Passwort mit einem `#` oder `=` kam so nie beim
     * Mailserver an. Die Geheimnisse müssen roh aus der Anfrage kommen.
     */
    public function testGeheimnisseKommenRohAusDerAnfrage(): void
    {
        foreach (self::SECRETS as $field) {
            self::assertTrue(
                $GLOBALS['TL_DCA']['tl_consho_mail_transport']['fields'][$field]['eval']['useRawRequestData'] ?? false,
                $field,
            );
        }
    }

    /**
     * Die Begründung am installierten Contao, nicht aus dem Gedächtnis: So
     * sähe ein Passwort nach dem gewöhnlichen Weg aus.
     */
    public function testContaoKodiertDieseZeichenImGewoehnlichenWeg(): void
    {
        self::assertSame('p&#35;ss&#61;w&#40;rd&#41;', Input::encodeInput('p#ss=w(rd)', InputEncodingMode::encodeAll, false));
    }
}

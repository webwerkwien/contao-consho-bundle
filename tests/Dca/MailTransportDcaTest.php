<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Contao\Input;
use Contao\InputEncodingMode;
use PHPUnit\Framework\TestCase;

class MailTransportDcaTest extends TestCase
{
    // The user name as well: o'brien@… is a legal address.
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
     * 🔴 C-9: the first real sending failed with "535 authentication failed".
     *
     * A text field reads through Input::post() in encodeAll mode and rewrites
     * `# < > ( ) \ = " '` into HTML entities — BEFORE TransportSecretListener
     * encrypts. A password with a `#` or `=` thus never reached the mail
     * server. The secrets must come raw from the request.
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
     * The reasoning checked against the installed Contao, not from memory: this
     * is what a password would look like after the ordinary path.
     */
    public function testContaoKodiertDieseZeichenImGewoehnlichenWeg(): void
    {
        self::assertSame('p&#35;ss&#61;w&#40;rd&#41;', Input::encodeInput('p#ss=w(rd)', InputEncodingMode::encodeAll, false));
    }
}

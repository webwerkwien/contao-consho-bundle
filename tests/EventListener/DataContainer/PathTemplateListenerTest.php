<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PathTemplateListener;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

class PathTemplateListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'pathTemplateUnknownPlaceholder' => '„%s" ist kein bekannter Platzhalter.',
            'pathTemplateNoIdentifier' => 'Die Adressvorlage braucht {alias} oder {sku}.',
            'pathTemplateRepeated' => 'Der Platzhalter {%s} kommt mehrfach vor.',
            'pathTemplateEmpty' => 'Die Adressvorlage darf nicht leer sein.',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testStoresTheNormalisedTemplate(): void
    {
        self::assertSame('{manufacturer}/{alias}', $this->save('/{manufacturer}/{alias}/'));
    }

    public function testRefusesACategoryAndSaysWhy(): void
    {
        /*
         * The refusal people will actually hit, because every other shop allows
         * a category in the product URL. The message has to carry the reason —
         * "invalid value" would read as a bug in Consho.
         */
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('„category" ist kein bekannter Platzhalter.');

        $this->save('{category}/{alias}');
    }

    public function testRefusesATemplateThatIdentifiesNothing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('braucht {alias} oder {sku}');

        $this->save('{manufacturer}');
    }

    public function testRefusesAnEmptyTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('darf nicht leer sein');

        $this->save('');
    }

    public function testFallsBackToTheEnglishMessageWhenNoTranslationIsLoaded(): void
    {
        // Saving through a console command or a migration has no language files
        // loaded. Refusing with an empty string would be worse than refusing in
        // English.
        unset($GLOBALS['TL_LANG']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('{alias} or {sku}');

        $this->save('{manufacturer}');
    }

    /**
     * A real recorder with mocked collaborators rather than a double of a final
     * class. It is never reached in these tests — every one of them has an
     * empty stored template, which returns before any recording — so an
     * interface invented for the test would buy nothing.
     *
     * ShopLanguages is `final` and cannot be doubled with createMock() either
     * (same note as ProductUrlGeneratorTest) — a real instance against a
     * mocked connection, never actually queried for the same reason.
     */
    private function recorder(): RedirectRecorder
    {
        return new RedirectRecorder(
            $this->createMock(Connection::class),
            $this->createMock(ProductPageRepositoryInterface::class),
            new ShopLanguages($this->createMock(Connection::class)),
        );
    }

    private function save(string $value): string
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn(['conshoPathTemplate' => '']);

        return (new PathTemplateListener($this->recorder()))($value, $dc);
    }
}

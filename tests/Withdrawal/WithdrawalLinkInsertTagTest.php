<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\OutputType;
use Contao\CoreBundle\InsertTag\ResolvedInsertTag;
use Contao\CoreBundle\InsertTag\ResolvedParameters;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalLinkInsertTag;

/**
 * WA, I1 (Michael, 2026-10-22: „Insert-Tag Widerruf-Link für z.B. Footer"):
 * der Link zur Seite „Vertrag widerrufen" des Startpunkts.
 */
final class WithdrawalLinkInsertTagTest extends TestCase
{
    public function testLinkMitDemGesetzlichenTextInDerSpracheDerSeite(): void
    {
        $result = $this->tag()->__invoke($this->insertTag('consho_withdrawal_link'));

        self::assertSame('<a href="https://shop.test/widerruf?a=1&amp;b=2">Vertrag widerrufen [en]</a>', $result->getValue());
        self::assertSame(OutputType::html, $result->getOutputType());
        self::assertSame(['contao.db.tl_page.3', 'contao.db.tl_page.12'], $result->getCacheTags(), 'Startpunkt und Zielseite');
    }

    public function testEigenerTextWirdMaskiert(): void
    {
        self::assertSame(
            '<a href="https://shop.test/widerruf?a=1&amp;b=2">Widerruf &lt;hier&gt;</a>',
            $this->tag()->__invoke($this->insertTag('consho_withdrawal_link', 'Widerruf <hier>'))->getValue(),
        );
    }

    public function testNurDieAdresse(): void
    {
        $result = $this->tag()->__invoke($this->insertTag('consho_withdrawal_url'));

        self::assertSame('https://shop.test/widerruf?a=1&b=2', $result->getValue());
        self::assertSame(OutputType::url, $result->getOutputType());
    }

    /** Nie ein Link ins Leere. */
    public function testLeerStattKaputt(): void
    {
        foreach (['consho_withdrawal_link', 'consho_withdrawal_url'] as $name) {
            self::assertSame('', $this->tag(page: false)->__invoke($this->insertTag($name))->getValue(), 'keine Seite');
            self::assertSame('', $this->tag(shop: false)->__invoke($this->insertTag($name))->getValue(), 'kein Shop');
            self::assertSame('', $this->tag(target: 0)->__invoke($this->insertTag($name))->getValue(), 'keine Seite eingestellt');
            self::assertSame('', $this->tag(routable: false)->__invoke($this->insertTag($name))->getValue(), 'Seite nicht erreichbar');
        }

        self::assertSame(['contao.db.tl_page.3', 'contao.db.tl_page.12'], $this->tag(routable: false)->__invoke($this->insertTag('consho_withdrawal_link'))->getCacheTags(), 'wird sie veröffentlicht, erscheint der Link');
    }

    private function insertTag(string $name, string ...$parameters): ResolvedInsertTag
    {
        return new ResolvedInsertTag($name, new ResolvedParameters(array_values($parameters)), []);
    }

    private function tag(bool $page = true, bool $shop = true, int $target = 12, bool $routable = true): WithdrawalLinkInsertTag
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnMap([['type', 'root'], ['id', 3], ['language', 'en']]);
        $root->method('row')->willReturn(['id' => 3, 'consho_shop' => $shop ? 7 : 0, 'consho_withdrawalFormPage' => $target]);

        $formPage = $this->createMock(PageModel::class);

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(static fn (string $method, array $arguments): PageModel|null => 'findById' === $method && 12 === $arguments[0] ? $formPage : null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([[PageModel::class, $pageAdapter]]);

        $generator = $this->createMock(ContentUrlGenerator::class);

        if ($routable) {
            $generator->method('generate')->willReturn('https://shop.test/widerruf?a=1&b=2');
        } else {
            $generator->method('generate')->willThrowException(new RouteNotFoundException());
        }

        $pages = $this->createMock(PageFinder::class);
        $pages->method('getCurrentPage')->willReturn($page ? $root : null);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters, string|null $domain, string|null $locale): string => 'CONSHO.withdrawal.button' === $id && 'contao_default' === $domain ? 'Vertrag widerrufen ['.$locale.']' : $id);

        $resolver = new ShopResolver($framework);

        return new WithdrawalLinkInsertTag($resolver, new ShopPageUrls($framework, $resolver, $generator), $pages, $translator);
    }
}

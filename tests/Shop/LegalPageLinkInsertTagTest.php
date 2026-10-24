<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shop;

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
use Webwerkwien\ContaoConshoBundle\Shop\LegalPageLinkInsertTag;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * WA, I1 (Michael, 2026-10-22: „Insert-Tag Widerruf-Link für z.B. Footer"):
 * der Link zur Seite „Vertrag widerrufen" des Startpunkts. Dasselbe für
 * „Verträge hier kündigen" (Michael, 2026-10-24: „brauchen wir das auch für
 * Abo Kündigen?" → „ja, gleich bauen").
 */
final class LegalPageLinkInsertTagTest extends TestCase
{
    public function testLinkMitDemGesetzlichenTextInDerSpracheDerSeite(): void
    {
        $result = $this->tag()->__invoke($this->insertTag('consho_withdrawal_link'));

        self::assertSame('<a href="https://shop.test/seite-12?a=1&amp;b=2">Vertrag widerrufen [en]</a>', $result->getValue());
        self::assertSame(OutputType::html, $result->getOutputType());
        self::assertSame(['contao.db.tl_page.3', 'contao.db.tl_page.12'], $result->getCacheTags(), 'Startpunkt und Zielseite');
    }

    /** Eigene Seite und eigener Text, nicht die des Widerrufs. */
    public function testKuendigungMitIhrerSeiteUndIhremText(): void
    {
        $result = $this->tag()->__invoke($this->insertTag('consho_cancellation_link'));

        self::assertSame('<a href="https://shop.test/seite-14?a=1&amp;b=2">Verträge hier kündigen [en]</a>', $result->getValue());
        self::assertSame(['contao.db.tl_page.3', 'contao.db.tl_page.14'], $result->getCacheTags());

        $url = $this->tag()->__invoke($this->insertTag('consho_cancellation_url'));
        self::assertSame('https://shop.test/seite-14?a=1&b=2', $url->getValue());
        self::assertSame(OutputType::url, $url->getOutputType());
    }

    public function testEigenerTextWirdMaskiert(): void
    {
        self::assertSame(
            '<a href="https://shop.test/seite-12?a=1&amp;b=2">Widerruf &lt;hier&gt;</a>',
            $this->tag()->__invoke($this->insertTag('consho_withdrawal_link', 'Widerruf <hier>'))->getValue(),
        );
    }

    public function testNurDieAdresse(): void
    {
        $result = $this->tag()->__invoke($this->insertTag('consho_withdrawal_url'));

        self::assertSame('https://shop.test/seite-12?a=1&b=2', $result->getValue());
        self::assertSame(OutputType::url, $result->getOutputType());
    }

    /** Nie ein Link ins Leere. */
    public function testLeerStattKaputt(): void
    {
        foreach (['consho_withdrawal_link', 'consho_withdrawal_url', 'consho_cancellation_link', 'consho_cancellation_url'] as $name) {
            self::assertSame('', $this->tag(page: false)->__invoke($this->insertTag($name))->getValue(), 'keine Seite');
            self::assertSame('', $this->tag(shop: false)->__invoke($this->insertTag($name))->getValue(), 'kein Shop');
            self::assertSame('', $this->tag(targets: false)->__invoke($this->insertTag($name))->getValue(), 'keine Seite eingestellt');
            self::assertSame('', $this->tag(routable: false)->__invoke($this->insertTag($name))->getValue(), 'Seite nicht erreichbar');
        }

        self::assertSame(['contao.db.tl_page.3', 'contao.db.tl_page.12'], $this->tag(routable: false)->__invoke($this->insertTag('consho_withdrawal_link'))->getCacheTags(), 'wird sie veröffentlicht, erscheint der Link');
    }

    private function insertTag(string $name, string ...$parameters): ResolvedInsertTag
    {
        return new ResolvedInsertTag($name, new ResolvedParameters(array_values($parameters)), []);
    }

    private function tag(bool $page = true, bool $shop = true, bool $targets = true, bool $routable = true): LegalPageLinkInsertTag
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnMap([['type', 'root'], ['id', 3], ['language', 'en']]);
        $root->method('row')->willReturn([
            'id' => 3,
            'consho_shop' => $shop ? 7 : 0,
            'consho_withdrawalFormPage' => $targets ? 12 : 0,
            'consho_cancellationPage' => $targets ? 14 : 0,
        ]);

        $targetPages = [12 => $this->createMock(PageModel::class), 14 => $this->createMock(PageModel::class)];
        $targetPages[12]->method('__get')->willReturnMap([['id', 12]]);
        $targetPages[14]->method('__get')->willReturnMap([['id', 14]]);

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(static fn (string $method, array $arguments): PageModel|null => 'findById' === $method ? ($targetPages[$arguments[0]] ?? null) : null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([[PageModel::class, $pageAdapter]]);

        $generator = $this->createMock(ContentUrlGenerator::class);

        if ($routable) {
            $generator->method('generate')->willReturnCallback(static fn (PageModel $target): string => 'https://shop.test/seite-'.$target->id.'?a=1&b=2');
        } else {
            $generator->method('generate')->willThrowException(new RouteNotFoundException());
        }

        $pages = $this->createMock(PageFinder::class);
        $pages->method('getCurrentPage')->willReturn($page ? $root : null);

        $texts = ['CONSHO.withdrawal.button' => 'Vertrag widerrufen', 'CONSHO.cancellation.button' => 'Verträge hier kündigen'];
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters, string|null $domain, string|null $locale): string => isset($texts[$id]) && 'contao_default' === $domain ? $texts[$id].' ['.$locale.']' : $id);

        $resolver = new ShopResolver($framework);

        return new LegalPageLinkInsertTag($resolver, new ShopPageUrls($framework, $resolver, $generator), $pages, $translator);
    }
}

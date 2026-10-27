<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;

class InvoiceProvidersTest extends TestCase
{
    public function testEinInstalliertesModulWirdUeberSeinenNamenGefunden(): void
    {
        $provider = new FakeInvoiceProvider();
        $providers = new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider]));

        self::assertSame($provider, $providers->get('fake_books'));
        self::assertSame(['fake_books'], $providers->names());
    }

    public function testConshoAusUndEinEntferntesModulHabenKeinenAnbieter(): void
    {
        $providers = new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => new FakeInvoiceProvider()]));

        self::assertNull($providers->get('consho'));
        self::assertNull($providers->get('off'));
        self::assertNull($providers->get('removed_module'));
        self::assertNull($providers->get(''));
    }
}

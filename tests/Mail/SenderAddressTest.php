<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Mail\SenderAddress;

/**
 * Contao stores form input encoded — in the sender of a mail the customer
 * reads what is stored there (Nachlese stage 2).
 */
class SenderAddressTest extends TestCase
{
    public function testDerNameKommtDekodiert(): void
    {
        $address = SenderAddress::of(['senderAddress' => 'shop@example.org', 'senderName' => 'Müller &amp; Co &#40;Wien&#41;']);

        self::assertSame('shop@example.org', $address->getAddress());
        self::assertSame('Müller & Co (Wien)', $address->getName());
    }

    public function testOhneNameNurDieAdresse(): void
    {
        self::assertSame('shop@example.org', SenderAddress::of(['senderAddress' => 'shop@example.org'])->toString());
    }
}

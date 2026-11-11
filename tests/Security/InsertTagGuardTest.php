<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webwerkwien\ContaoConshoBundle\Security\InsertTagGuard;

class InsertTagGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function values(): iterable
    {
        yield 'Tag' => ['Firma {{date}}', true];
        yield 'nur öffnend' => ['Firma {{', true];
        yield 'nur schließend' => ['}} GmbH', true];
        yield 'einzelne Klammern' => ['Firma {Holding}', false];
        yield 'getrennte Klammern' => ['{ {date} }', false];
        yield 'leer' => ['', false];
        yield 'gewöhnlicher Name' => ['Erika Muster', false];
    }

    #[DataProvider('values')]
    public function testErkenntDoppelteGeschweifteKlammern(string $value, bool $contains): void
    {
        self::assertSame($contains, InsertTagGuard::contains($value));
    }

    public function testNenntDieFelderUndUeberspringtWasKeinTextIst(): void
    {
        self::assertSame(['company', 'note'], InsertTagGuard::fieldsWith([
            'company' => 'Firma {{date}}',
            'name' => 'Erika Muster',
            'quantity' => ['1' => '{{date}}'],
            'note' => '}}',
            'count' => 3,
        ]));
    }

    /**
     * Logged with the form and the field names; the input itself stays out of
     * the system log.
     */
    public function testProtokolliertFormularUndFelderOhneDieEingabe(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::callback(
            static fn (string $message): bool => str_contains($message, '"withdrawal"')
                && str_contains($message, 'name, message')
                && !str_contains($message, 'env::host'),
        ));

        self::assertTrue((new InsertTagGuard($logger))->refuses('withdrawal', ['name' => 'Test {{env::host}}', 'email' => 'a@example.com', 'message' => '{{date}}']));
    }

    /**
     * The keys come from the request: a key of the sender's making is
     * counted, not written into the log, and a flood of them is cut short.
     */
    public function testFremdeFeldnamenWerdenGezaehltStattProtokolliert(): void
    {
        $values = ['billing_city' => '{{date}}', "x<b>\n{{date}}" => '{{date}}', str_repeat('a', 41) => '}}'];

        for ($i = 1; $i <= 6; ++$i) {
            $values['field'.$i] = '{{date}}';
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::identicalTo(
            'Insert tag in customer input refused (form "checkout", fields billing_city, field1, field2, field3, field4, 4 other)',
        ));

        self::assertTrue((new InsertTagGuard($logger))->refuses('checkout', $values));
    }

    public function testOhneTagKeinEintragUndKeineAblehnung(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        self::assertFalse((new InsertTagGuard($logger))->refuses('withdrawal', ['name' => 'Erika Muster', 'quantity' => ['{{date}}']]));
    }
}

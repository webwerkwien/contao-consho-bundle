<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\LegalTextKind;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;

/**
 * What C-7 added to the order is a RECORD, and a record has two duties: it has
 * to hold every value it is given, and nobody may rewrite it afterwards.
 *
 * Both are one-line settings in a DCA and neither fails loudly when it is
 * wrong — a column too short truncates silently, and a missing notDeletable
 * shows up the day someone deletes something.
 */
class OrderRecordDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_order.php';
        require __DIR__.'/../../contao/dca/tl_consho_order_item.php';
        require __DIR__.'/../../contao/dca/tl_consho_order_legal.php';
        require __DIR__.'/../../contao/dca/tl_consho_payment_attempt.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    /**
     * `paymentDiscount` is fifteen characters and the column holds sixteen.
     *
     * That is one to spare, so this is not pedantry: a fifth case with a
     * longer name would be cut to sixteen on the way in, `stored()` would not
     * recognise it and every such line would come back as a product. Nothing
     * would throw.
     *
     * @return iterable<string, array{class-string<\BackedEnum>, string, string}>
     */
    public static function storedEnums(): iterable
    {
        yield 'line type' => [OrderLineType::class, 'tl_consho_order_item', 'lineType'];
        yield 'legal text kind' => [LegalTextKind::class, 'tl_consho_order_legal', 'kind'];
        yield 'attempt state' => [AttemptState::class, 'tl_consho_payment_attempt', 'state'];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @dataProvider storedEnums
     */
    public function testJederWertDerAufzaehlungPasstInSeineSpalte(string $enum, string $table, string $field): void
    {
        $length = $GLOBALS['TL_DCA'][$table]['fields'][$field]['sql']['length'];

        foreach ($enum::cases() as $case) {
            self::assertLessThanOrEqual(
                $length,
                \strlen((string) $case->value),
                \sprintf('%s.%s holds %d characters, "%s" needs %d.', $table, $field, $length, $case->value, \strlen((string) $case->value)),
            );
        }
    }

    public function testEineBestellungLaesstSichNichtLoeschen(): void
    {
        // Deleting an order releases its shipping and payment method from
        // MethodInUseVoter; restoring it from tl_undo afterwards resurrects a
        // reference to a method that may be gone by then.
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_order']['config']['notDeletable']);
    }

    public function testDerRechtstextSchnappschussIstWederAenderbarNochLoeschbar(): void
    {
        $config = $GLOBALS['TL_DCA']['tl_consho_order_legal']['config'];

        // The whole point of the table: it says what the customer read. A row
        // an operator can edit says what the operator wishes they had read.
        self::assertTrue($config['notEditable']);
        self::assertTrue($config['notDeletable']);
        self::assertTrue($config['notCopyable']);

        // And no `new` — a row typed by hand would look exactly like evidence.
        self::assertArrayNotHasKey('global_operations', $GLOBALS['TL_DCA']['tl_consho_order_legal']['list']);
    }

    public function testDerRechtstextHaengtAnDerBestellungUndIstVonIhrErreichbar(): void
    {
        self::assertContains('tl_consho_order_legal', $GLOBALS['TL_DCA']['tl_consho_order']['config']['ctable']);
        self::assertSame('table=tl_consho_order_legal', $GLOBALS['TL_DCA']['tl_consho_order']['list']['operations']['legal']['href']);
    }

    public function testDerVersuchIstEindeutigUeberSeinenTokenUndImBackendUnsichtbar(): void
    {
        $config = $GLOBALS['TL_DCA']['tl_consho_payment_attempt']['config'];

        // The notification route looks an attempt up BY its hash; a duplicate
        // would make one provider callback resolve to two attempts.
        self::assertSame('unique', $config['sql']['keys']['tokenHash']);

        self::assertTrue($config['notEditable']);
        self::assertTrue($config['notDeletable']);

        // Not in the order's ctable either: an attempt that says `succeeded`
        // because someone typed it is worse than no record at all.
        self::assertNotContains('tl_consho_payment_attempt', $GLOBALS['TL_DCA']['tl_consho_order']['config']['ctable']);
    }

    public function testDerTokenDesVersuchsPasstAufEinenSha256(): void
    {
        // 64 characters is exactly a sha256 in hex. A shorter column would
        // truncate the hash and every lookup would fail — silently, because a
        // failed lookup is indistinguishable from an unknown token.
        self::assertSame(64, $GLOBALS['TL_DCA']['tl_consho_payment_attempt']['fields']['tokenHash']['sql']['length']);
        self::assertSame(64, $GLOBALS['TL_DCA']['tl_consho_order_legal']['fields']['checksum']['sql']['length']);
    }
}

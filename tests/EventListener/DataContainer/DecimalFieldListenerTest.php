<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DecimalFieldListener;

/**
 * The form shows decimal fields in the backend language, without the
 * column's zeros: "1" instead of "1.0000", "2,5" instead of "2.5000",
 * amounts with two decimals. It showed on the minimum quantity of the
 * price form.
 */
class DecimalFieldListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testMengenOhneNullenUndMitKommaImDeutschen(): void
    {
        self::assertSame('1', DecimalFieldListener::display('1.0000', false, ','));
        self::assertSame('2,5', DecimalFieldListener::display('2.5000', false, ','));
        self::assertSame('0,125', DecimalFieldListener::display('0.1250', false, ','));
        self::assertSame('100', DecimalFieldListener::display('100.0000', false, ','));
        self::assertSame('-3', DecimalFieldListener::display('-3.0000', false, ','));
        self::assertSame('2.5', DecimalFieldListener::display('2.5000', false, '.'));
    }

    public function testBetraegeMitMindestensZweiStellen(): void
    {
        self::assertSame('25,00', DecimalFieldListener::display('25.0000', true, ','));
        self::assertSame('25,50', DecimalFieldListener::display('25.5000', true, ','));
        self::assertSame('12,3456', DecimalFieldListener::display('12.3456', true, ','));
        self::assertSame('-4,10', DecimalFieldListener::display('-4.1000', true, ','));
        self::assertSame('0,00', DecimalFieldListener::display('0.0000', true, ','));
    }

    public function testKeineZahlBleibtWieSieIst(): void
    {
        self::assertNull(DecimalFieldListener::display(null, false, ','));
        self::assertSame('', DecimalFieldListener::display('', false, ','));
        self::assertSame('2,5', DecimalFieldListener::display('2,5', false, ','));
    }

    public function testHaengtSichNurAnDezimalfelderOhneEigenenLoadCallback(): void
    {
        $GLOBALS['TL_DCA']['tl_consho_x'] = ['config' => [], 'fields' => [
            'quantity' => ['inputType' => 'text', 'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4]],
            'total' => ['inputType' => 'text', 'eval' => ['conshoMoney' => true], 'sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4]],
            'own' => ['inputType' => 'text', 'load_callback' => [static fn ($v) => 'eigener'], 'sql' => ['type' => 'decimal', 'precision' => 3, 'scale' => 1]],
            'always' => ['inputType' => 'text', 'eval' => ['alwaysSave' => true], 'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4]],
            'net' => ['sql' => ['type' => 'decimal', 'precision' => 12, 'scale' => 4]],
            'title' => ['inputType' => 'text', 'sql' => ['type' => 'string', 'length' => 255]],
        ]];
        $GLOBALS['TL_LANG']['MSC']['decimalSeparator'] = ',';

        $listener = new DecimalFieldListener(new RequestStack());
        $listener('tl_consho_x');

        $fields = $GLOBALS['TL_DCA']['tl_consho_x']['fields'];
        self::assertCount(1, $fields['quantity']['load_callback'] ?? []);
        self::assertCount(1, $fields['total']['load_callback'] ?? []);
        self::assertCount(1, $fields['own']['load_callback']);
        self::assertArrayNotHasKey('load_callback', $fields['always']);
        self::assertArrayNotHasKey('load_callback', $fields['net']);
        self::assertArrayNotHasKey('load_callback', $fields['title']);

        $dc = $this->createMock(DataContainer::class);
        self::assertSame('2,5', $fields['quantity']['load_callback'][0]('2.5000', $dc));
        self::assertSame('25,00', $fields['total']['load_callback'][0]('25.0000', $dc));
        self::assertSame('eigener', $fields['own']['load_callback'][0]('2.5', $dc));
    }

    public function testFremdeTabellenBleibenUnberuehrt(): void
    {
        $GLOBALS['TL_DCA']['tl_content'] = ['fields' => ['x' => ['inputType' => 'text', 'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4]]]];

        (new DecimalFieldListener(new RequestStack()))('tl_content');

        self::assertArrayNotHasKey('load_callback', $GLOBALS['TL_DCA']['tl_content']['fields']['x']);
    }

    /**
     * The form shows "2,5", what is stored is "2.5000": for Contao that is a
     * change, and a save without a change would write a new version and run
     * through every onbeforesubmit as if somebody had changed the quantity.
     */
    public function testUnveraendertGespeichertIstKeineAenderung(): void
    {
        $values = $this->submit(['quantity' => '2.5', 'tstamp' => 1700000000], ['quantity' => '2.5000'], 'tl_consho_x');

        self::assertSame([], $values);
    }

    public function testEineEchteAenderungBleibt(): void
    {
        $values = $this->submit(['quantity' => '3', 'tstamp' => 1700000000], ['quantity' => '2.5000'], 'tl_consho_x');

        self::assertSame(['quantity' => '3', 'tstamp' => 1700000000], $values);
    }

    public function testAndereFelderBleibenNebenDerUnveraendertenMenge(): void
    {
        $values = $this->submit(['quantity' => '2.5', 'title' => 'Neu', 'tstamp' => 1700000000], ['quantity' => '2.5000'], 'tl_consho_x');

        self::assertSame(['title' => 'Neu', 'tstamp' => 1700000000], $values);
    }

    /**
     * contao-ai and Connect call onbeforesubmit when writing as well. There
     * the value does not come from the form, and an empty set of values would
     * be an UPDATE without columns.
     */
    public function testAusserhalbDerMaskeBleibtAllesStehen(): void
    {
        $values = $this->submit(['quantity' => '2.5', 'tstamp' => 1700000000], ['quantity' => '2.5000'], null);

        self::assertSame(['quantity' => '2.5', 'tstamp' => 1700000000], $values);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function submit(array $values, array $record, string|null $formSubmit): array
    {
        $GLOBALS['TL_DCA']['tl_consho_x'] = ['config' => [], 'fields' => [
            'quantity' => ['inputType' => 'text', 'sql' => ['type' => 'decimal', 'precision' => 10, 'scale' => 4]],
            'title' => ['inputType' => 'text', 'sql' => ['type' => 'string', 'length' => 255]],
        ]];

        $requests = new RequestStack();
        $requests->push(new Request([], null === $formSubmit ? [] : ['FORM_SUBMIT' => $formSubmit]));

        (new DecimalFieldListener($requests))('tl_consho_x');

        $dc = $this->getMockBuilder(DataContainer::class)->disableOriginalConstructor()->getMock();
        $dc->method('getCurrentRecord')->willReturn($record);
        $dc->method('__get')->willReturnCallback(static fn (string $key) => 'table' === $key ? 'tl_consho_x' : null);

        $callback = $GLOBALS['TL_DCA']['tl_consho_x']['config']['onbeforesubmit_callback'][0];

        return $callback($values, $dc);
    }
}

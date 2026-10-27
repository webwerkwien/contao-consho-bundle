<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * A disabled select with a `foreignKey` needs an empty option.
 *
 * WG-2: a voucher issued by hand belongs to no order (`orderId` 0). Without
 * an empty option the select has no matching entry, and the browser shows
 * the FIRST one — „Gekauft mit" and some order number. That is not saved
 * (disabled fields are not submitted), but the form claims it. The same
 * stood in every manual stock movement since Block G and in 18 more fields.
 */
class DisabledSelectShowsNothingTest extends TestCase
{
    public function testJedesGesperrteAuswahlfeldHatEineLeereOption(): void
    {
        $missing = [];

        // Consho's own tables: the extensions of Contao's (tl_member …) need
        // Contao's DCA loaded first.
        foreach (glob(__DIR__.'/../../contao/dca/tl_consho_*.php') ?: [] as $file) {
            $GLOBALS['TL_DCA'] = [];
            include $file;

            foreach ($GLOBALS['TL_DCA'] as $table => $dca) {
                foreach ($dca['fields'] ?? [] as $name => $config) {
                    if ('select' === ($config['inputType'] ?? '') && ($config['eval']['disabled'] ?? false) && isset($config['foreignKey']) && !($config['eval']['includeBlankOption'] ?? false)) {
                        $missing[] = $table.'.'.$name;
                    }
                }
            }
        }

        unset($GLOBALS['TL_DCA']);

        self::assertSame([], $missing);
    }
}

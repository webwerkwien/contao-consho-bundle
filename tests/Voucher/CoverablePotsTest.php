<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Voucher;

use Contao\CoreBundle\Intl\Countries;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingShare;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Voucher\CoverablePot;
use Webwerkwien\ContaoConshoBundle\Voucher\CoverablePots;

/**
 * EZ-1, F2, F4: per tax class and country, the gross of the articles less
 * the cart rule's discount on them, at today's rate — no voucher line, and
 * nothing under reverse charge.
 */
class CoverablePotsTest extends TestCase
{
    public function testJeKlasseUndLandNachRabatt(): void
    {
        $pots = $this->pots()->of(
            [
                $this->line(1, '60.0000', 1, 'AT'),
                $this->line(2, '30.0000', 1, 'AT'),
                $this->line(3, '11.0000', 2, 'AT'),
                $this->line(4, '59.5000', 1, 'DE'),
                // WG8: a voucher line is no article a voucher pays for.
                $this->line(5, '50.0000', 1, 'AT', ProductKind::Voucher),
                // Without a class or a country nothing priced it.
                $this->line(6, '10.0000', 0, 'AT'),
            ],
            [
                new ShippingShare(1, '-10.0000', '-12.0000', 'AT'),
                // A share without a country of its own is taxed where the order is.
                new ShippingShare(2, '-1.0000', '-1.1000', ''),
                // A discount on something no pot holds changes nothing.
                new ShippingShare(3, '-5.0000', '-6.0000', 'AT'),
            ],
            1_800_000_000,
            false,
            'AT',
        );

        self::assertSame(
            [[1, 'AT', '20.0000', '78.0000'], [2, 'AT', '10.0000', '9.9000'], [1, 'DE', '19.0000', '59.5000']],
            array_map(static fn (CoverablePot $pot): array => [$pot->taxClass, $pot->taxCountry, $pot->taxRate, $pot->amount], $pots),
        );
    }

    /**
     * The basket has the cart rule's shares only net; grossed here at the
     * rate of what they reduce and negated, they take off the pot what the
     * checkout takes off — not nothing, as the preview did before.
     */
    public function testDerWarenkorbZiehtDenRabattAbWieDieKasse(): void
    {
        $shares = $this->pots()->discountShares(
            [
                new ShippingShare(1, '10.0000', '0.0000', 'AT'),
                // Without a country of its own: where the basket is priced.
                new ShippingShare(2, '1.0000', '0.0000', ''),
                // Without a rate the amount stays as it is, as in the checkout.
                new ShippingShare(1, '5.0000', '0.0000', 'CH'),
            ],
            'AT',
            1_800_000_000,
        );

        self::assertSame(
            [[1, '-10.0000', '-12.0000', 'AT'], [2, '-1.0000', '-1.1000', ''], [1, '-5.0000', '-5.0000', 'CH']],
            array_map(static fn (ShippingShare $share): array => [$share->taxClassId, $share->net, $share->gross, $share->taxCountry], $shares),
        );

        $pots = $this->pots()->of([$this->line(1, '60.0000', 1, 'AT')], $shares, 1_800_000_000, false, 'AT');
        self::assertSame('48.0000', $pots[0]->amount);
    }

    public function testUnterReverseChargeNichts(): void
    {
        self::assertSame([], $this->pots()->of([$this->line(1, '60.0000', 1, 'AT')], [], 1_800_000_000, true));
    }

    public function testOhneSatzKeinTopf(): void
    {
        self::assertSame([], $this->pots()->of([$this->line(1, '60.0000', 1, 'CH')], [], 1_800_000_000, false));
    }

    /**
     * A single-purpose voucher fixes the place of supply at its issue (Art. 30a
     * no. 2 VAT Directive): goods taxed in the shop's country only because the
     * distance sale stays below the threshold are not supplied there when they
     * travel abroad. A service is taxed where it is performed, wherever the
     * customer lives.
     */
    public function testNurWasImSteuerlandAnkommt(): void
    {
        $lines = [
            $this->line(1, '60.0000', 1, 'AT'),
            $this->line(2, '12.0000', 1, 'AT', ProductKind::Digital),
            $this->line(3, '30.0000', 1, 'AT', ProductKind::Service),
            $this->line(4, '59.5000', 1, 'DE'),
        ];

        $abroad = $this->pots()->of($lines, [], 1_800_000_000, false, 'AT', 'de');
        self::assertSame(
            [[1, 'AT', '30.0000'], [1, 'DE', '59.5000']],
            array_map(static fn (CoverablePot $pot): array => [$pot->taxClass, $pot->taxCountry, $pot->amount], $abroad),
        );

        $home = $this->pots()->of($lines, [], 1_800_000_000, false, 'AT', 'AT');
        self::assertSame('102.0000', $home[0]->amount);

        // Without a delivery country (the basket before an address) nothing is left out.
        self::assertSame('102.0000', $this->pots()->of($lines, [], 1_800_000_000, false, 'AT')[0]->amount);
    }

    /**
     * The cart rule's share covers every line of its class and country. Where
     * the goods go abroad, only the part on what stays reduces the pot: goods
     * 90 and a service 30, 10 % off (-12) — at home 108, abroad 27, not 18.
     */
    public function testImAuslandNurDerRabattAufWasBleibt(): void
    {
        $lines = [
            $this->line(1, '90.0000', 1, 'AT'),
            $this->line(2, '30.0000', 1, 'AT', ProductKind::Service),
        ];
        $shares = [new ShippingShare(1, '-10.0000', '-12.0000', 'AT')];

        self::assertSame('108.0000', $this->pots()->of($lines, $shares, 1_800_000_000, false, 'AT', 'AT')[0]->amount);
        self::assertSame('27.0000', $this->pots()->of($lines, $shares, 1_800_000_000, false, 'AT', 'DE')[0]->amount);

        // A large discount no longer drives the pot below zero: -40 of 120,
        // a quarter of it on the service — 30 - 10 = 20.
        $large = [new ShippingShare(1, '-33.3333', '-40.0000', 'AT')];
        self::assertSame('20.0000', $this->pots()->of($lines, $large, 1_800_000_000, false, 'AT', 'DE')[0]->amount);

        // Rounded towards the larger discount: -10 on a third is -3.3334.
        $third = [$this->line(1, '60.0000', 1, 'AT'), $this->line(2, '30.0000', 1, 'AT', ProductKind::Service)];
        self::assertSame('26.6666', $this->pots()->of($third, [new ShippingShare(1, '-8.3333', '-10.0000', 'AT')], 1_800_000_000, false, 'AT', 'DE')[0]->amount);
    }

    /**
     * A rule need not reach every line: with its split per line the pot takes
     * exactly the discount on what it kept. Goods 90 abroad, the service 30
     * stays; the rule gives 20 off — net, per line (20 % AT).
     */
    public function testMitDerAufteilungJeZeileGenau(): void
    {
        $lines = [
            $this->line(1, '90.0000', 1, 'AT'),
            $this->line(2, '30.0000', 1, 'AT', ProductKind::Service),
        ];
        $share = [new ShippingShare(1, '-16.6667', '-20.0000', 'AT')];
        $now = 1_800_000_000;

        // Only on the service: all 20 of it come off — 10 left, not 25.
        self::assertSame('10.0000', $this->pots()->of($lines, $share, $now, false, 'AT', 'DE', [1 => '16.6667'])[0]->amount);
        // Only on the goods: nothing comes off the service — 30 stay, not 25.
        self::assertSame('30.0000', $this->pots()->of($lines, $share, $now, false, 'AT', 'DE', [0 => '16.6667'])[0]->amount);
        // At home nothing is left out: the share, whatever its split.
        self::assertSame('100.0000', $this->pots()->of($lines, $share, $now, false, 'AT', 'AT', [1 => '16.6667'])[0]->amount);
    }

    private function line(int $id, string $gross, int $class, string $country, ProductKind $kind = ProductKind::Physical): CartLine
    {
        return new CartLine($id, $id, $id, '1.0000', '0.0000', '0.0000', $gross, $class, 0, 0, $country, kind: $kind);
    }

    private function pots(): CoverablePots
    {
        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(
            static fn (int $class, string $country): string|null => match ($country) {
                'AT' => 1 === $class ? '20.0000' : '10.0000',
                'DE' => '19.0000',
                default => null,
            },
        );

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'CH']);

        return new CoverablePots(new TaxResolver($rates, new CountryProvider($countries)));
    }
}

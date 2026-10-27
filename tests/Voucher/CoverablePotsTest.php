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

<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The brake on guessing codes in the basket (block Wertgutscheine, WG5).
 *
 * A discount code saves a few euros; a voucher code IS money. Until WG-1 the
 * basket took any number of codes, so a script could try them one after the
 * other until one fitted.
 *
 * Only FAILED attempts count — an unknown code. Whoever types a valid code,
 * or the same valid code twice, never meets the brake; only trying many
 * wrong ones does. Counted per IP and per basket: per IP alone a basket
 * behind a shared address (an office, a mobile network) would lock out its
 * neighbours less than it should protect, per basket alone a new basket
 * would start from zero.
 *
 * The limiter is configured like the others in config/services.yaml — one
 * storage, one entry per purpose.
 */
final class CodeAttemptLimit
{
    public function __construct(
        #[Autowire(service: 'consho.rate_limit.code_entry')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * Whether either count is used up. Asks without counting: consume(0)
     * takes nothing and reports what is left.
     */
    public function blocked(string $ip, int $cartId): bool
    {
        foreach ($this->keys($ip, $cartId) as $key) {
            if ($this->limiter->create($key)->consume(0)->getRemainingTokens() < 1) {
                return true;
            }
        }

        return false;
    }

    public function failed(string $ip, int $cartId): void
    {
        foreach ($this->keys($ip, $cartId) as $key) {
            $this->limiter->create($key)->consume();
        }
    }

    /**
     * @return list<string>
     */
    private function keys(string $ip, int $cartId): array
    {
        return ['ip-'.('' === $ip ? 'unknown' : $ip), 'cart-'.$cartId];
    }
}
